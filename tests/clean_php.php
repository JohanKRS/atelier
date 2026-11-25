<?php
declare(strict_types=1);
// Limpar on the server (app/Clean.php) gives what the page's filter (assets/clean.js) gives: the same picture cleaned by
// both, at every level, matches to within one level of rounding (the page keeps its sums in 32-bit floats). Then the
// file version: strips join into the whole image, alpha stays, and the result is a file the library takes.
require dirname(__DIR__).'/app/bootstrap.php';
use Studio\{Clean,HttpError};

$count=0;
function check(bool $condition,string $message): void { global $count; if(!$condition)throw new RuntimeException($message);$count++;echo "PASS $message\n"; }

// A picture with what the filter tells apart: a plain surface with a faint web and dots, a hard edge, a thin line, a
// plaid, a soft gradient and colored noise, at an odd size.
function picture(int $W,int $H,int $seed): array {
    $px=[];
    for($y=0;$y<$H;$y++) for($x=0;$x<$W;$x++){
        $seed=($seed*1103515245+12345)&0x7fffffff; $n=($seed/0x7fffffff-0.5)*16;
        if($x>=(int)($W*0.66)) $v=[$y<$H/2?40:200,$y<$H/2?60:190,$y<$H/2?90:170];
        elseif($y>=(int)($H*0.66)) { $p=70+($x%8<2?70:0)+($y%8<2?70:0); $v=[$p,$p,$p]; }
        else $v=[(int)round(120+$n+$x*0.3),(int)round(110+$n*0.7),(int)round(100+$n*1.2+$y*0.2)];
        foreach($v as $c) $px[]=max(0,min(255,$c));
        $px[]=255;
    }
    foreach([[10,10],[30,22],[50,40],[22,50]] as [$x,$y]) foreach([[0,0],[1,0],[0,1],[1,1]] as [$dx,$dy]) { $p=(($y+$dy)*$W+$x+$dx)*4; $px[$p]=$px[$p+1]=$px[$p+2]=235; }
    for($x=4;$x<(int)($W*0.6);$x++){ $p=(32*$W+$x)*4; $px[$p]=$px[$p+1]=$px[$p+2]=30; }
    $px[(5*$W+5)*4+3]=100;
    return $px;
}

$W=113;$H=97;$image=picture($W,$H,12345);
$script=dirname(__DIR__).'/assets/clean.js';
$input=tempnam(sys_get_temp_dir(),'atelier-clean-');file_put_contents($input,json_encode(['image'=>$image,'W'=>$W,'H'=>$H]));
$js=json_decode((string)shell_exec('node --input-type=module -e '.escapeshellarg(
    'import "'.dirname(__DIR__).'/tests/i18n-setup.mjs"; import { clean, LEVELS } from "'.$script.'"; import { readFileSync } from "node:fs";'
    .'const { image, W, H } = JSON.parse(readFileSync(process.argv[1], "utf8")); const out = {};'
    .'for (const level of Object.keys(LEVELS)) out[level] = Array.from(clean(new Uint8ClampedArray(image), W, H, LEVELS[level]));'
    .'process.stdout.write(JSON.stringify(out));').' '.escapeshellarg($input).' 2>&1'),true);
unlink($input);
check(is_array($js)&&array_keys($js)===array_keys(Clean::LEVELS),'the page\'s filter ran on the same picture at every level');
foreach(Clean::LEVELS as $name=>$level){
    $php=Clean::pixels($image,$W,$H,$level);
    $off=0;$most=0;$changed=0;
    foreach($php as $i=>$v){ $d=abs($v-$js[$name][$i]); if($d)$off++; $most=max($most,$d); if($v!==$image[$i])$changed++; }
    check($most<=1&&$off<=count($php)*0.01&&$changed>count($php)*0.2,"Limpar $name on the server matches the page (".$off.' of '.count($php)." values off by one, none by more)");
}
for($i=3;$i<count($image);$i+=4) if($php[$i]!==$image[$i]) throw new RuntimeException('alpha changed');
check(true,'alpha stays as it was');

// The file: strips of rows join into the whole image cleaned at once.
$im=imagecreatetruecolor($W,$H); imagealphablending($im,false); imagesavealpha($im,true);
for($y=0,$p=0;$y<$H;$y++) for($x=0;$x<$W;$x++,$p+=4) imagesetpixel($im,$x,$y,(($image[$p+3]===255?0:63)<<24)|($image[$p]<<16)|($image[$p+1]<<8)|$image[$p+2]);
ob_start(); imagepng($im); $png=(string)ob_get_clean(); imagedestroy($im);
$whole=Clean::file($png,'media');
$parts=Clean::file($png,'media',null,$W*40);
check(count(Clean::strips($H,$W,$W*40))===2&&$parts['bytes']===$whole['bytes'],'strips cleaned apart join into exactly the whole image');
check(Clean::strips(1000,1000)[0]===['from'=>0,'to'=>Clean::strips(1000,1000)[0]['to'],'top'=>0,'bottom'=>Clean::strips(1000,1000)[0]['to']+Clean::MARGIN]&&array_sum(array_map(fn($s)=>$s['to']-$s['from'],Clean::strips(4321,3000)))===4321,'strips cover every row once, with the margin around them');
$out=imagecreatefromstring($whole['bytes']);
$rgb=imagecolorat($out,10,10); $a=(imagecolorat($out,5,5)>>24)&0x7F;
check($whole['mime']==='image/png'&&$whole['width']===$W&&$whole['height']===$H&&imagesx($out)===$W&&abs((($rgb>>16)&0xFF)-$js['media'][(10*$W+10)*4])<=1,'the result is a PNG of the same size, cleaned as in the page');
check($a===63&&((imagecolorat($out,6,5)>>24)&0x7F)===0,'a translucent pixel keeps its alpha in the file');
$progress=[];Clean::file($png,'leve',function(float $d)use(&$progress){$progress[]=$d;},$W*40);
check(count($progress)>=3&&end($progress)===1.0&&$progress==array_values(array_filter($progress,fn($v)=>$v>=0&&$v<=1)),'progress rises to 1 across the strips');
$sorted=$progress;sort($sorted);check($sorted===$progress,'progress never goes back');
try{Clean::file($png,'extreme');check(false,'an unknown level is refused');}catch(HttpError){check(true,'an unknown level is refused');}
try{Clean::file('not an image','media');check(false,'data that is not an image is refused');}catch(HttpError){check(true,'data that is not an image is refused');}
// A JPEG comes back as a PNG, at its size.
$im=imagecreatetruecolor(40,30); ob_start(); imagejpeg($im); $jpeg=(string)ob_get_clean(); imagedestroy($im);
$fromJpeg=Clean::file($jpeg,'forte');
check($fromJpeg['mime']==='image/png'&&$fromJpeg['width']===40&&$fromJpeg['height']===30,'a JPEG is cleaned into a PNG of its size');
echo "\n$count checks passed\n";
