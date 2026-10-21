<?php
declare(strict_types=1);

/**
 * Read-only change history. SQL stored audit payloads are never rendered as
 * HTML. Employees have no access, while their business actions remain logged.
 */
function adc_audit_labels(): array {
    return [
        'bootstrap_director'=>'Первоначальная настройка директора',
        'staging_schema_upgrade'=>'Обновлены настройки системы',
        'create_employee'=>'Создание сотрудника',
        'deactivate_employee'=>'Отключение сотрудника',
        'change_password'=>'Смена пароля',
        'reset_employee_password'=>'Сброс пароля сотрудника',
        'create_client'=>'Добавлен клиент',
        'edit_client'=>'Изменена карточка клиента',
        'resume_client'=>'Возобновлено сотрудничество',
        'edit_engagement'=>'Изменены условия сотрудничества',
        'archive_engagement'=>'Сотрудничество завершено',
        'create_next_period'=>'Создан очередной расчётный период',
        'add_period'=>'Добавлен расчётный период',
        'add_charge'=>'Добавлена работа или начисление',
        'edit_charge'=>'Исправлена строка начисления',
        'void_charge'=>'Аннулирована строка начисления',
        'receive_payment'=>'Получена оплата',
        'refund'=>'Возврат клиенту',
        'create_demo_client'=>'Добавлен демо-клиент (устаревшее действие)',
        'delete_demo_client'=>'Удалён демо-клиент',
        'upload_document'=>'Загружен документ',
        'replace_document'=>'Заменён документ',
        'void_document'=>'Удалён документ',
        'transfer_document'=>'Документ передан',
        'invalidate_document'=>'Документ устарел после изменения суммы',
        'create_task'=>'Добавлена личная задача',
        'complete_task'=>'Выполнена личная задача',
        'edit_task'=>'Отредактирована личная задача',
        'delete_task'=>'Удалена личная задача',
        'import_history_csv'=>'Импортирована история клиентов',
    ];
}

function adc_audit_panel(PDO $db, array $user): string {
    if (($user['role'] ?? '')!=='director') return '';
    $before=filter_var($_GET['audit_before'] ?? null,FILTER_VALIDATE_INT);
    if ($before===false || $before===null || $before<1) $before=null;
    $sql='SELECT a.id,a.action,a.object_type,a.object_id,a.details_json,a.occurred_at,
       COALESCE(u.display_name,"Система") actor
       FROM audit_events a LEFT JOIN users u ON u.id=a.actor_user_id';
    $sql.= $before===null?'':' WHERE a.id < ?';
    $sql.=' ORDER BY a.id DESC LIMIT 51';
    $stmt=$db->prepare($sql);
    $stmt->execute($before===null?[]:[$before]);
    $rows=$stmt->fetchAll();
    $more=count($rows)>50;
    $rows=array_slice($rows,0,50);
    $labels=adc_audit_labels();
    $html='<details class="card"><summary>Журнал изменений</summary>'.
        '<p class="muted">Показаны последние 50 действий. Более ранние записи доступны ниже.</p>';
    foreach ($rows as $item) {
        $label=$labels[$item['action']]??'Действие системы';
        $html.='<div class="item"><div><strong>'.adc_h($label).'</strong>'.
            '<p class="muted">'.adc_h($item['occurred_at']).' UTC · '.
            adc_h($item['actor']).' · '.adc_h([
                    'client'=>'Клиент','user'=>'Сотрудник','engagement'=>'Сотрудничество',
                    'personal_task'=>'Задача','database'=>'Система',
                    'document_bundle'=>'Документ','payment_event'=>'Оплата',
                    'charge_line'=>'Начисление','billing_period'=>'Период'
                ][$item['object_type']] ?? 'Изменение').
            ($item['object_id']===null?'':' #'.(int)$item['object_id']).'</p></div></div>';
    }
    if (!$rows) $html.='<p class="muted">Записей пока нет.</p>';
    if ($more) {
        $last=$rows[count($rows)-1];
        $html.='<a class="btn secondary" href="/?view=team&audit_before='.(int)$last['id'].
            '">Более ранние записи →</a>';
    }
    return $html.'</details>';
}
