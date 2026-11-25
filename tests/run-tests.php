<?php
require '/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php'; require_once ABSPATH.'wp-admin/includes/image.php'; require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/template.php';
header('Content-Type: text/plain'); wp_set_current_user(1); activate_plugin('ng-advanced-media-tools/ng-advanced-media-tools.php');
$pass=0;$fail=0; $t=function($n,$c) use(&$pass,&$fail){ if($c){$pass++;echo "PASS $n\n";}else{$fail++;echo "FAIL $n\n";} };
$MOCK=isset($_GET['mock'])?$_GET['mock']:'http://127.0.0.1:9500';
// import
$r=NGAMT::import('javascript:alert(1)'); $t('javascript: URL rejected',is_wp_error($r));
$r=NGAMT::import('http://10.0.0.5/x.jpg'); $t('private host blocked by default (SSRF guard)',is_wp_error($r)&&$r->get_error_code()==='unsafe');
$r=NGAMT::import('http://169.254.169.254/latest/meta-data'); $t('metadata IP blocked',is_wp_error($r)); $r=NGAMT::import('http://192.168.1.1/x.jpg'); $t('LAN IP blocked',is_wp_error($r)&&$r->get_error_code()==='unsafe');
add_filter('ngamt_allow_unsafe_url','__return_true');
$r=NGAMT::import($MOCK.'/img.jpg','Alt ZZ'); $t('import jpg from URL',is_int($r)); 
if(is_int($r)){ $t('alt text saved',get_post_meta($r,'_wp_attachment_image_alt',true)==='Alt ZZ'); $t('source recorded',strpos(get_post_meta($r,'_ngamt_source',true),'/img.jpg')!==false); $t('sizes generated',count(wp_get_attachment_metadata($r)['sizes']??array())>=2); $img=$r; }
$r=NGAMT::import($MOCK.'/notimage.txt'); $t('non-image rejected',is_wp_error($r)&&$r->get_error_code()==='type');
$r=NGAMT::import($MOCK.'/big.jpg'); $t('oversize rejected',is_wp_error($r)&&$r->get_error_code()==='big');
$r=NGAMT::import($MOCK.'/missing.jpg'); $t('404 handled',is_wp_error($r));
$r=NGAMT::import($MOCK.'/fake.jpg'); $t('text disguised as .jpg rejected',is_wp_error($r)&&$r->get_error_code()==='type');
// orphan scan
$mkimg=function($n){ $im=imagecreatetruecolor(1200,800); for($i=0;$i<200;$i++){imagefilledrectangle($im,rand(0,1200),rand(0,800),rand(0,1200),rand(0,800),imagecolorallocate($im,rand(0,255),rand(0,255),rand(0,255)));} $f=tempnam(sys_get_temp_dir(),'t').'.jpg'; imagejpeg($im,$f,85); return media_handle_sideload(array('name'=>$n,'tmp_name'=>$f),0); };
$a=$mkimg('orph-unused.jpg'); $b=$mkimg('used-in-post.jpg'); $c=$mkimg('used-featured.jpg'); $d=$mkimg('used-in-meta.jpg'); $e=$mkimg('used-by-id.jpg'); $g=$mkimg('used-in-option.jpg'); $h=$mkimg('draft-only.jpg');
$u=wp_get_attachment_url($b); wp_insert_post(array('post_title'=>'P1','post_status'=>'publish','post_content'=>'<img src="'.$u.'">'));
$pc=wp_insert_post(array('post_title'=>'P2','post_status'=>'publish','post_content'=>'x')); set_post_thumbnail($pc,$c);
$pm=wp_insert_post(array('post_title'=>'P3','post_status'=>'publish','post_content'=>'x')); update_post_meta($pm,'_elementor_data','[{"settings":{"image":{"url":"'.str_replace('/','\/',wp_get_attachment_url($d)).'"}}}]');
wp_insert_post(array('post_title'=>'P4','post_status'=>'publish','post_content'=>'<!-- wp:image {"id":'.$e.',"sizeSlug":"large"} --><figure><img class="wp-image-'.$e.'"/></figure><!-- /wp:image -->'));
update_option('widget_custom_html',array(array('content'=>'<img src="'.wp_get_attachment_url($g).'">')));
wp_insert_post(array('post_title'=>'Trash','post_status'=>'trash','post_content'=>'<img src="'.wp_get_attachment_url($h).'">'));
$scan=NGAMT::scan();
$t('unused image found',in_array($a,$scan,true)); $t('used in content kept',!in_array($b,$scan,true)); $t('featured image kept',!in_array($c,$scan,true)); $t('used in builder meta kept',!in_array($d,$scan,true));
$t('used by block id kept',!in_array($e,$scan,true)); $t('used in widget option kept',!in_array($g,$scan,true)); $t('only in trashed post counts as unused',in_array($h,$scan,true));
// resized variant referenced
$m=wp_get_attachment_metadata($a); $t('has resized sizes',!empty($m['sizes']));
// quarantine
$files=NGAMT::files_for($a); $orig=get_attached_file($a); $t('quarantine moves files',NGAMT::quarantine($a)&&!file_exists($orig)&&count(glob(NGAMT::qdir().'/'.$a.'/*'))===count($files));
$t('quarantined not rescanned',!in_array($a,NGAMT::scan(),true));
$t('restore brings files back',NGAMT::restore($a)&&file_exists($orig)&&count(NGAMT::files_for($a))===count($files)&&!file_exists(NGAMT::qdir().'/'.$a));
$t('purge refuses non-quarantined',NGAMT::purge($a)===false&&file_exists($orig));
NGAMT::quarantine($a); $t('purge deletes quarantined permanently',NGAMT::purge($a)&&!get_post($a)&&!file_exists(NGAMT::qdir().'/'.$a));
// permissions
wp_set_current_user(0); $caught=false; try{ ob_start(); NGAMT::page(); $o=ob_get_clean(); $caught=($o===''); }catch(Throwable $x){} $t('page empty without capability',$caught); wp_set_current_user(1);
// pages
foreach(array('en_US'=>'Media Tools','ar'=>'أدوات الوسائط','fr_FR'=>'Outils média','de_DE'=>'Medien-Werkzeuge') as $loc=>$w){ add_filter('ngamt_locale',function()use($loc){return $loc;}); ob_start(); NGAMT::page(); $o=ob_get_clean(); $t("admin page $loc",strpos($o,$w)!==false); remove_all_filters('ngamt_locale'); }
$_GET['msg']='<script>alert(1)</script>'; ob_start(); NGAMT::page(); $o=ob_get_clean(); $t('msg escaped',strpos($o,'<script>alert')===false);
echo "\nTOTAL pass=$pass fail=$fail\n";
