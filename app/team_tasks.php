<?php
declare(strict_types=1);

/**
 * Director delegated tasks deliberately reuse personal_tasks without MySQL DDL.
 * A durable audit marker distinguishes assignments from private staff tasks.
 * Staff continue to own completion/editing of their own tasks.
 */
function adc_team_task_staff(PDO $db): array {
    return $db->query('SELECT id,display_name,role FROM users WHERE is_active=1
        ORDER BY role,display_name,id')->fetchAll(PDO::FETCH_ASSOC);
}


/** Load assignment ownership and optional client links in one audit query. */
function adc_team_task_metadata(PDO $db,array $tasks):array {
    $ids=array_values(array_unique(array_map(static fn($task)=>(int)$task['id'],$tasks)));
    if (!$ids)return [];
    $markers=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$db->prepare('SELECT object_id,details_json FROM audit_events
        WHERE object_type="personal_task" AND action="assign_team_task"
        AND object_id IN ('.$markers.') ORDER BY id DESC');
    $stmt->execute($ids);
    $metadata=[];
    foreach($stmt as $row){
        $id=(int)$row['object_id'];
        if (isset($metadata[$id]))continue;
        $detail=json_decode((string)$row['details_json'],true);
        $metadata[$id]=[
            'is_delegated'=>1,
            'linked_client_id'=>(int)(is_array($detail)?($detail['client_id']??0):0)
        ];
    }
    return $metadata;
}

function adc_team_task_options(array $staff): array {
    $options=[];
    foreach ($staff as $member) {
        $options[(int)$member['id']]=(string)$member['display_name'].
            ($member['role']==='director'?' (директор)':'');
    }
    return $options;
}

function adc_team_task_assignee(PDO $db,int $id): void {
    $stmt=$db->prepare('SELECT id FROM users WHERE id=? AND is_active=1 FOR UPDATE');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Исполнитель не найден или его учётная запись отключена.');
    }
}

function adc_team_task_marker(PDO $db,int $taskId): bool {
    $stmt=$db->prepare('SELECT id FROM audit_events
        WHERE object_type="personal_task" AND object_id=? AND action="assign_team_task"
        LIMIT 1');
    $stmt->execute([$taskId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Open, auditable control assignments. A task is linked to the original
 * client/kind/date, even if the director later changes its title or deadline.
 * No personal tasks are exposed or treated as delegated work.
 */
function adc_team_control_key(int $clientId,string $kind,string $date): string {
    return $clientId.':'.$kind.':'.$date;
}

function adc_team_control_assignments(PDO $db): array {
    $rows=$db->query('SELECT t.id,t.user_id,u.display_name,a.details_json
        FROM audit_events a
        JOIN personal_tasks t ON t.id=a.object_id AND t.is_done=0
        JOIN users u ON u.id=t.user_id
        WHERE a.object_type="personal_task" AND a.action="assign_team_task"
        ORDER BY a.id DESC');
    $assigned=[];
    foreach ($rows as $row) {
        $detail=json_decode((string)$row['details_json'],true);
        if (!is_array($detail) || ($detail['source']??'')!=='control') continue;
        $id=(int)($detail['client_id']??0);
        $kind=(string)($detail['kind']??'');
        $date=(string)($detail['reminder_date']??'');
        if ($id<=0 || !adc_valid_date($date) || !in_array($kind,
            ['invoice','act','spec','contract','handover','replace'],true)) continue;
        $key=adc_team_control_key($id,$kind,$date);
        // Historical duplicates are tolerated but never generate extra tasks.
        if (!isset($assigned[$key])) {
            $assigned[$key]=[
                'task_id'=>(int)$row['id'],
                'assignee_user_id'=>(int)$row['user_id'],
                'display_name'=>(string)$row['display_name']
            ];
        }
    }
    return $assigned;
}

function adc_team_task_action(PDO $db,array $user,string $action): bool {
    if (!in_array($action,[
        'assign_team_task','assign_control_reminder','assign_control_bulk',
        'edit_team_task','cancel_team_task'
    ],true)) return false;
    adc_require_director($user);
    $actor=(int)$user['id'];
    $db->beginTransaction();
    try {
        if (in_array($action,['assign_team_task','assign_control_reminder','assign_control_bulk'],true)) {
            $isControl=$action!=='assign_team_task';
            if ($isControl) {
                // Serialize concurrent control dispatches, even to DIFFERENT
                // employees. Locks are released automatically by commit/rollback.
                $db->query('SELECT id FROM users WHERE role="director"
                    ORDER BY id LIMIT 1 FOR UPDATE')->fetchColumn();
            }
            $assignee=adc_w_id('assignee_user_id');
            adc_team_task_assignee($db,$assignee);
            if ($isControl) {
                $requested=[];
                if ($action==='assign_control_bulk') {
                    $selected=$_POST['selected_reminders']??null;
                    if (!is_array($selected) || count($selected)<1 || count($selected)>60) {
                        throw new RuntimeException('Выберите от 1 до 60 действий для назначения.');
                    }
                    foreach ($selected as $raw) {
                        if (!is_string($raw) || !preg_match(
                            '/\A[1-9][0-9]{0,15}:(invoice|act|spec|contract|handover|replace):[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',
                            $raw)) {
                            throw new RuntimeException('Некорректный выбор действий. Обновите «Контроль».');
                        }
                        $requested[$raw]=true;
                    }
                } else {
                    $id=adc_w_id('client_id');
                    $kind=adc_input('reminder_kind');
                    $date=adc_w_date(adc_input('reminder_date'));
                    $requested[adc_team_control_key($id,$kind,$date)]=true;
                }
                // Every item must exist in the CURRENT server-derived plan.
                // A forged title, client, or obsolete checkbox is rejected.
                $matching=[];
                foreach (adc_client_reminders($db,date('Y-m-d')) as $row) {
                    $key=adc_team_control_key((int)$row['client_id'],
                        (string)$row['kind'],(string)$row['date']);
                    if (isset($requested[$key])) $matching[$key]=$row;
                }
                if (count($matching)!==count($requested)) {
                    throw new RuntimeException('Напоминание изменилось. Обновите раздел «Контроль».');
                }
                $existing=adc_team_control_assignments($db);
                foreach ($requested as $key=>$_) {
                    if (isset($existing[$key])) {
                        throw new RuntimeException('Это поручение уже есть у исполнителя. Обновите «Контроль».');
                    }
                }
            } else {
                $title=adc_validate_task_title(adc_input('title'));
                $due=adc_w_date(adc_input('due_date'));
                $repeat=isset($_POST['repeat_monthly'])?1:0;
            }
            $insert=$db->prepare('INSERT INTO personal_tasks
                (user_id,title,due_date,repeat_monthly,anchor_day) VALUES (?,?,?,?,?)');
            $created=0;
            if (!$isControl) {
                $insert->execute([$assignee,$title,$due,$repeat,
                    $repeat?(int)substr($due,8,2):null]);
                $id=(int)$db->lastInsertId();
                adc_audit($db,$actor,'assign_team_task','personal_task',$id,
                    ['source'=>'manual','assignee_user_id'=>$assignee,'due_date'=>$due]);
                $created=1;
            } else {
                foreach ($requested as $key=>$_) {
                    $row=$matching[$key];
                    $due=(string)$row['date'];
                    $title=adc_validate_task_title(mb_substr(
                        (string)$row['label'].' — '.(string)$row['client_name'],0,240));
                    $insert->execute([$assignee,$title,$due,0,null]);
                    $id=(int)$db->lastInsertId();
                    adc_audit($db,$actor,'assign_team_task','personal_task',$id,[
                        'source'=>'control','client_id'=>(int)$row['client_id'],
                        'kind'=>(string)$row['kind'],'reminder_date'=>$due,
                        'assignee_user_id'=>$assignee,'due_date'=>$due
                    ]);
                    $created++;
                }
            }
            $db->commit();
            adc_flash($isControl
                ?'Назначено поручений: '.$created.'. Исполнитель увидит их в «Моих задачах».'
                :'Поручение назначено. Исполнитель увидит его в «Моих задачах».');
            adc_redirect($isControl?'?view=control':'?view=tasks');
        }
        $taskId=adc_w_id('task_id');
        $q=$db->prepare('SELECT * FROM personal_tasks WHERE id=? AND is_done=0 FOR UPDATE');
        $q->execute([$taskId]);
        $old=$q->fetch(PDO::FETCH_ASSOC);
        if (!$old || !adc_team_task_marker($db,$taskId)) {
            throw new RuntimeException('Чужую личную задачу или завершённое поручение менять нельзя.');
        }
        if ($action==='cancel_team_task') {
            $db->prepare('UPDATE personal_tasks SET is_done=1,completed_at=NULL WHERE id=?')
                ->execute([$taskId]);
            adc_audit($db,$actor,'cancel_team_task','personal_task',$taskId,
                ['assignee_user_id'=>(int)$old['user_id']]);
            $db->commit();
            adc_flash('Поручение отменено. История сохранена.');
            adc_redirect('?view=tasks');
        }
        $assignee=adc_w_id('assignee_user_id');
        adc_team_task_assignee($db,$assignee);
        $title=adc_validate_task_title(adc_input('title'));
        $due=adc_w_date(adc_input('due_date'));
        $repeat=isset($_POST['repeat_monthly'])?1:0;
        $sameRecurring=(int)$old['repeat_monthly']===1 && $old['due_date']===$due;
        $anchor=$repeat?($sameRecurring?(int)$old['anchor_day']:(int)substr($due,8,2)):null;
        $db->prepare('UPDATE personal_tasks SET user_id=?,title=?,due_date=?,
            repeat_monthly=?,anchor_day=? WHERE id=? AND is_done=0')
            ->execute([$assignee,$title,$due,$repeat,$anchor,$taskId]);
        adc_audit($db,$actor,'edit_team_task','personal_task',$taskId,[
            'previous_assignee_user_id'=>(int)$old['user_id'],
            'assignee_user_id'=>$assignee,'due_date'=>$due,
        ]);
        $db->commit();
        adc_flash('Поручение обновлено.');
        adc_redirect('?view=tasks');
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
function adc_team_task_create_html(array $staff): string {
    if (!$staff) return '';
    return '<details class="card"><summary>Поручить задачу сотруднику</summary>'.
        adc_w_form_start('assign_team_task').
        '<div class="grid">'.
        adc_w_select('Исполнитель','assignee_user_id',adc_team_task_options($staff)).
        adc_w_field('Название','title','','text','required maxlength="240"').
        adc_w_field('Когда напомнить','due_date',date('Y-m-d'),'date','required').
        '</div>'.adc_w_check('repeat_monthly','Повторять каждый месяц',false).
        '<button type="submit">Назначить</button></form></details>';
}

function adc_team_task_manage_html(array $task,array $staff): string {
    $id=(int)$task['id'];
    return '<details class="task-inline-edit"><summary>Управлять поручением</summary>'.
        adc_w_form_start('edit_team_task').adc_w_hidden('task_id',$id).
        '<div class="grid">'.
        adc_w_select('Исполнитель','assignee_user_id',adc_team_task_options($staff),(string)$task['user_id']).
        adc_w_field('Название','title',(string)$task['title'],'text','required maxlength="240"').
        adc_w_field('Когда напомнить','due_date',(string)$task['due_date'],'date','required').
        '</div>'.adc_w_check('repeat_monthly','Повторять каждый месяц',(int)$task['repeat_monthly']===1).
        '<button type="submit">Сохранить поручение</button></form>'.
        adc_w_form_start('cancel_team_task').adc_w_hidden('task_id',$id).
        '<button class="secondary" type="submit">Отменить поручение</button></form></details>';
}

function adc_team_task_control_form(array $staff,array $row): string {
    if (!$staff) return '';
    return adc_w_form_start('assign_control_reminder').
        adc_w_hidden('client_id',(int)$row['client_id']).
        adc_w_hidden('reminder_kind',(string)$row['kind']).
        adc_w_hidden('reminder_date',(string)$row['date']).
        adc_w_select('Исполнитель','assignee_user_id',adc_team_task_options($staff)).
        '<button class="secondary" type="submit">Поручить</button></form>';
}

function adc_team_task_completions(PDO $db): string {
    $rows=$db->query('SELECT t.title,c.due_date,c.completed_at,u.display_name
        FROM personal_task_completions c
        JOIN personal_tasks t ON t.id=c.task_id
        JOIN users u ON u.id=c.user_id
        WHERE c.completed_at>=UTC_TIMESTAMP()-INTERVAL 30 DAY
        ORDER BY c.completed_at DESC,c.id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    $html='<details class="card"><summary>Выполнено командой за 30 дней (до 50 последних)</summary>';
    if (!$rows)return $html.'<p class="muted">Завершений пока нет.</p></details>';
    foreach($rows as $row){
        $html.='<div class="item"><div><strong>'.adc_h((string)$row['title']).
            '</strong><p class="muted">'.adc_h((string)$row['display_name']).
            ' · срок '.adc_h((string)$row['due_date']).
            ' · выполнено '.adc_h((string)$row['completed_at']).
            ' UTC</p></div></div>';
    }
    return $html.'</details>';
}
