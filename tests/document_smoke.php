<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/documents.php';

function assert_doc(bool $okay, string $description): void {
    if (!$okay) throw new RuntimeException('Document security test failed: '.$description);
}
assert_doc(str_ends_with(adc_doc_directory(),'/adclients-documents'),'Private directory name');
assert_doc(!str_contains(adc_doc_directory(),'/public_html/'),'Not under publicly served root');
try {
    adc_doc_path('../index.php');
    throw new RuntimeException('Traversal accepted');
} catch (RuntimeException $expected) {
    assert_doc(str_contains($expected->getMessage(),'идентификатор'),'Reject path traversal');
}
$key=str_repeat('a',48).'.pdf';
assert_doc(str_ends_with(adc_doc_path($key),'/'.$key),'Opaque safe filename');
$temp=tempnam(sys_get_temp_dir(),'adc-pdf-');
if (!$temp) throw new RuntimeException('Cannot create temp test document');
try {
    file_put_contents($temp,"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    adc_doc_pdf($temp);
    file_put_contents($temp,"<html>Not a PDF</html>");
    try {
        adc_doc_pdf($temp);
        throw new RuntimeException('Non-PDF accepted');
    } catch (RuntimeException $expected) {
        assert_doc(str_contains($expected->getMessage(),'PDF'),'Reject non-PDF');
    }
} finally { unlink($temp); }
echo "Private PDF storage and validation smoke tests passed\n";
