<?php
ini_set('display_errors',1); error_reporting(E_ALL);
register_shutdown_function(function(){ $e=error_get_last(); if($e&&in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) echo "SHUTDOWN ".$e['message']." @".$e['file'].":".$e['line']."\n"; });
require '/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php'; require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/image.php'; require_once ABSPATH.'wp-admin/includes/template.php'; require_once ABSPATH.'wp-admin/includes/taxonomy.php';
header('Content-Type: text/plain'); wp_set_current_user(1);
$pass=0;$fail=0; $t=function($n,$c) use(&$pass,&$fail){ if($c){$pass++;echo "PASS $n\n";}else{$fail++;echo "FAIL $n\n";} };
$t('Elementor active',defined('ELEMENTOR_VERSION')); echo "ELEMENTOR_VERSION=".(defined('ELEMENTOR_VERSION')?ELEMENTOR_VERSION:'none')."\n";
foreach(array('ng-light-image-optimizer','ng-performance-control','ng-advanced-media-tools','ng-schema-linker','ng-seo-health-monitor','ng-ai-content-automator','ng-ai-media-assistant','ng-cloud-offloader') as $p){ $f=glob(WP_PLUGIN_DIR."/$p/$p.php"); if(!$f){echo "SKIP $p (not mounted)\n";continue;} $r=activate_plugin("$p/$p.php"); $t("activate $p",!is_wp_error($r)&&is_plugin_active("$p/$p.php")); }
$mk=function($n,$c) { $im=imagecreatetruecolor(1000,700); imagefilledrectangle($im,0,0,1000,700,imagecolorallocate($im,$c,100,50)); for($i=0;$i<60;$i++){imagefilledellipse($im,rand(0,1000),rand(0,700),rand(20,200),rand(20,200),imagecolorallocate($im,rand(0,255),rand(0,255),rand(0,255)));} $f=tempnam(sys_get_temp_dir(),'t').'.jpg'; imagejpeg($im,$f,85); return media_handle_sideload(array('name'=>$n,'tmp_name'=>$f),0); };
$used=$mk('el-used.jpg',200); $unused=$mk('el-unused.jpg',20); $t('attachments created',is_int($used)&&is_int($unused));
$url=wp_get_attachment_url($used);
$data=array(array('id'=>'a1b2c3d','elType'=>'container','settings'=>array(),'elements'=>array(array('id'=>'e4f5a6b','elType'=>'widget','widgetType'=>'image','settings'=>array('image'=>array('url'=>$url,'id'=>$used,'size'=>'','alt'=>'')),'elements'=>array()),array('id'=>'f7a8b9c','elType'=>'widget','widgetType'=>'heading','settings'=>array('title'=>'Smoke heading'),'elements'=>array()))));
$pid=wp_insert_post(array('post_title'=>'Elementor smoke page','post_name'=>'el-smoke','post_status'=>'publish','post_type'=>'post','post_content'=>''));
update_post_meta($pid,'_elementor_edit_mode','builder'); update_post_meta($pid,'_elementor_template_type','wp-post'); update_post_meta($pid,'_elementor_version',ELEMENTOR_VERSION); update_post_meta($pid,'_elementor_data',wp_slash(wp_json_encode($data)));
echo "PERMALINK=".get_permalink($pid)."\n";
$res=NGAMT::scan(); $t('media tools: Elementor-only image NOT flagged unused',!in_array($used,$res)); $t('media tools: truly unused image IS flagged',in_array($unused,$res));
$g=NGSL::graph(get_post($pid)); $t('schema graph builds for Elementor post',!empty($g['@graph']));
echo "PASS=$pass FAIL=$fail\n"; echo "UPLOADURL=$url\n";
