<?php
require __DIR__.'/../app/bootstrap.php';
use Legends\PdfBuilder;
use Legends\Packager;

$work = sys_get_temp_dir().'/lg_'.bin2hex(random_bytes(4));
mkdir($work, 0700, true);

// synthetic signature PNG
$sig = imagecreatetruecolor(300,90); $w=imagecolorallocate($sig,255,255,255); imagefilledrectangle($sig,0,0,300,90,$w);
$b=imagecolorallocate($sig,20,20,40);
imagesetthickness($sig,3);
imageline($sig,10,60,60,20,$b); imageline($sig,60,20,90,70,$b); imageline($sig,90,70,140,25,$b); imageline($sig,140,25,260,55,$b);
imagepng($sig, "$work/signature.png"); imagedestroy($sig);

// synthetic headshot
$hs = imagecreatetruecolor(400,500); $bg=imagecolorallocate($hs,210,220,230); imagefilledrectangle($hs,0,0,400,500,$bg);
$fc=imagecolorallocate($hs,180,150,130); imagefilledellipse($hs,200,220,160,200,$fc);
imagejpeg($hs,"$work/headshot.jpg",82); imagedestroy($hs);

// synthetic PDF upload (void cheque)
$vp = "$work/gov_document.pdf";
file_put_contents($vp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");

$data = [
  'privacy_ack'=>'1','first_name'=>'Jane','middle_name'=>'A','last_name'=>"O'Brien-Smith",
  'preferred_name'=>'Janie','pronouns'=>'she/her','date_of_birth'=>'1996-04-12',
  'street_address'=>'123 Really Long Example Street Name Boulevard','unit'=>'4B','city'=>'Toronto',
  'province'=>'Ontario','postal_code'=>'M5V 2T6','mobile_phone'=>'(416) 555-0142','home_phone'=>'',
  'other_phone'=>'','primary_email'=>'jane@example.com','secondary_email'=>'',
  'ec1_name'=>'John OBrien','ec1_relationship'=>'Father','ec1_phone'=>'(416) 555-0199','ec1_email'=>'john@example.com',
  'ec2_name'=>'','ec2_relationship'=>'','ec2_phone'=>'','ec2_email'=>'',
  'desired_hours'=>'32','availability_comments'=>'Prefer evenings. Not available during exam weeks in December and April.',
  'allergies'=>'Peanuts','medical_conditions'=>'',
  'sin'=>'046454286','sin_issued'=>'2015-01-01','sin_expiry'=>'',
  'gov_first_name'=>'Jane','gov_middle_name'=>'A','gov_last_name'=>"O'Brien-Smith",
  'gov_doc_type'=>"Ontario Driver's Licence",'gov_doc_number'=>'O1234-56789-01234','gov_issued_by'=>'ServiceOntario','gov_issued_date'=>'2022-03-01','gov_expiry_date'=>'2027-04-12',
  'dd_institution_name'=>'Royal Bank of Canada','dd_account_holder'=>'Jane O\'Brien-Smith','dd_transit'=>'00123','dd_institution_number'=>'003','dd_account_number'=>'1234567',
  'smartserve_last_name'=>"O'Brien-Smith",'smartserve_cert_id'=>'SS-99887','smartserve_issued'=>'2024-06-01','smartserve_expiry'=>'2029-06-01',
  'declaration_ack'=>'1','comms_consent'=>'1','preferred_contact'=>'text','employee_name'=>"Jane O'Brien-Smith",'signature_date'=>date('Y-m-d'),
  'avail_monday_enabled'=>'1','avail_monday_start'=>'17:00','avail_monday_end'=>'23:00',
  'avail_friday_enabled'=>'1','avail_friday_start'=>'16:00','avail_friday_end'=>'23:30',
  'avail_saturday_enabled'=>'1','avail_saturday_start'=>'11:00','avail_saturday_end'=>'23:00',
];
$files = [
  'gov_document'=>['field'=>'gov_document','label'=>'Government ID (scan/photo)','kind'=>'doc','path'=>$vp,'ext'=>'pdf','mime'=>'application/pdf','size'=>filesize($vp)],
  'headshot'=>['field'=>'headshot','label'=>'Headshot photograph','kind'=>'image','path'=>"$work/headshot.jpg",'ext'=>'jpg','mime'=>'image/jpeg','size'=>filesize("$work/headshot.jpg")],
];
$meta = ['reference'=>'AB12CD','timestamp'=>date('Y-m-d H:i T'),'reference_date'=>date('Y-m-d')];

$pdf = (new PdfBuilder($data,$files,"$work/signature.png",$meta))->render($work);
echo "PDF: $pdf  (".filesize($pdf)." bytes, magic ".substr(file_get_contents($pdf),0,5).")\n";
copy($pdf, '/tmp/out_employee.pdf');

$pk = new Packager('Test-Passphrase-12345','LegendsGlobal-NewHire');
$res = $pk->build($data,$pdf,$files,$meta,$work);
echo "PACKAGE: {$res['filename']} method={$res['method']} bytes=".filesize($res['path'])."\n";
copy($res['path'], '/tmp/'.$res['filename']);

// verify decrypt
$z=new ZipArchive(); $z->open($res['path']); $z->setPassword('Test-Passphrase-12345');
echo "ZIP entries: ".$z->numFiles."\n";
for($i=0;$i<$z->numFiles;$i++){ $s=$z->statIndex($i); echo "  - {$s['name']} ({$s['size']}b)\n"; }
$readme=$z->getFromName('README.txt'); echo "README ok: ".(strlen((string)$readme)>0?'yes':'NO')."\n";
$z->close();

// cleanup
array_map('unlink', glob("$work/*")); rmdir($work);
echo "DONE\n";
