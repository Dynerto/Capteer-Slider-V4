<?php
/*
Plugin Name: dynerto coupled slider X
Description: Vector‑gedreven sliders met ingebouwde importer (deep‑debug‑log). Data wordt als native array opgeslagen (geen JSON‑strings).
Version:     3.5.1
Author:      Dynerto
*/
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DCSX_DIR', plugin_dir_path( __FILE__ ) );
require_once DCSX_DIR . 'class-dcsx-renderer.php';

/*──────────────────────────────────── 1. CPT’s ───────────────────────────────────*/
add_action( 'init', function () {
	register_post_type( 'dcsx_slider', [
		'label'      => 'nx sliders',
		'public'     => false,
		'show_ui'    => true,
		'menu_icon'  => 'dashicons-leftright',
		'supports'   => [ 'title' ],
	] );
	register_post_type( 'dcsx_slider_group', [
		'label'      => 'nx slider groups',
		'public'     => false,
		'show_ui'    => true,
		'menu_icon'  => 'dashicons-share',
		'supports'   => [ 'title' ],
	] );
} );

/*──────────────────────────── 2. Defaults & helpers ─────────────────────────────*/
function dcsx_colors(){ return [ 'paars','rood','blauw','oranje','groen','geel','zilver' ]; }

function dcsx_default_mapping(){
	$hoek=[180,0,240,60,120,300,90]; $m=[];
	foreach ( dcsx_colors() as $i=>$c )
		$m[$c]=[
			'veld'          => '',
			'vector_hoek'   => $hoek[$i],
			'vector_lengte' => 1,
			'kleur'         => [ '#8656a5','#f15b4e','#2074c8','#f7931e','#2eb67d','#f2c230','#b7b7b7' ][$i],
			'outline'       => [ '#604073','#a43a27','#163758','#a7671a','#207657','#b6a120','#9a9a9a' ][$i],
			'motivatie'     => ''
		];
	return $m;
}
function dcsx_default_slider(){ return [
	'label'=>'','woord_links'=>'','woord_rechts'=>'',
	'breedte'=>220,'hoogte'=>18,'vector_px'=>1.1,
	'gradient_left'=>'#60a9f7','gradient_right'=>'#60a9f7',
	'outline'=>'#e5e8ee','markers_aan'=>1,
	'buttons'=>[[
		'naam'=>'knop 1','knopkleur'=>'#2074c8','knop_outline'=>'#333333',
		'icoon_svg'=>'','mapping'=>dcsx_default_mapping(),'extra_vectors'=>[]
	]]
];}
function dcsx_default_group(){ return [
	'sliders'=>[],'padding'=>38,'bolletjes_overlappen'=>0,'sliders_overlappen'=>0,
	'beziers'=>[ 'knop 1'=>[ 'show'=>1,'color'=>'#444444','dikte'=>2.8 ] ]
];}

function dcsx_slider_from_json(array $j){
	$s=array_replace_recursive( dcsx_default_slider(), $j );
	foreach ( $s['buttons'] as &$b )
		$b['mapping'] = array_replace_recursive( dcsx_default_mapping(), $b['mapping'] ?? [] );
	return $s;
}

/*──────────────────────── 3. Import‑submenu met deep‑log ─────────────────────────*/
add_action( 'admin_menu', function(){
	add_submenu_page(
		'edit.php?post_type=dcsx_slider',
		'NX Import', 'Import', 'manage_options',
		'dcsx_import', 'dcsx_import_page'
	);
} );
function dcsx_log( $key, $val ){ global $dcsx_log; $dcsx_log[$key] = $val; }

function dcsx_import_page(){
	global $dcsx_log; $dcsx_log=[];

	if ( isset( $_POST['dcsx_json'] ) && check_admin_referer( 'dcsx_imp' ) ){

		$raw = preg_replace( ['/\/\/.*$/m','/,\s*([\]}])/'], ['','$1'], trim( wp_unslash( $_POST['dcsx_json'] ) ) );
		dcsx_log( 'raw_length', strlen( $raw ) );
		$data = json_decode( $raw, true );

		if ( ! $data || empty( $data['group'] ) || empty( $data['sliders'] ) ){
			dcsx_log( 'error', 'ongeldige json' );
			echo '<div class="notice notice-error"><p>Ongeldige JSON.</p></div>';

		}else{

			$slider_ids=[];
			foreach ( $data['sliders'] as $idx=>$sl ){
				$pid = wp_insert_post( [
					'post_type'   => 'dcsx_slider',
					'post_status' => 'publish',
					'post_title'  => sanitize_text_field( $sl['title'] ?? 'slider' ),
				], true );

				if ( is_wp_error( $pid ) ){
					dcsx_log( "slider_$idx", [ 'err'=>$pid->get_error_message() ] );
					continue;
				}

				$meta = dcsx_slider_from_json( $sl['data'] ?? [] );
				update_post_meta( $pid, '_dcsx_slider', $meta );

				dcsx_log( "slider_$idx", [
					'id'=>$pid,
					'import_json'=>$sl['data'] ?? [],
					'saved_meta'=>$meta
				]);

				$slider_ids[]=$pid;
			}

			$gid = wp_insert_post( [
				'post_type'   => 'dcsx_slider_group',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( $data['group']['title'] ?? 'groep' ),
			], true );

			if ( ! is_wp_error( $gid ) ){
				$gm          = $data['group'];
				$gm['sliders']=$slider_ids; unset( $gm['title'] );
				update_post_meta( $gid, '_dcsx_group', $gm );

				dcsx_log( 'group', [
					'id'=>$gid,
					'import_json'=>$data['group'],
					'saved_meta'=>$gm
				]);

				echo '<div class="notice notice-success"><p>Import gelukt – shortcode: <code>[dcsx_slider_group id="'.$gid.'"]</code></p></div>';

			}else{
				dcsx_log( 'group_err', $gid->get_error_message() );
			}
		}

		update_option( 'dcsx_last_import_log', $dcsx_log );
	}

	$old = get_option( 'dcsx_last_import_log', [] );

	echo '<div class="wrap"><h1>NX Import</h1>
		<form method="post">
		<textarea name="dcsx_json" rows="18" style="width:100%;font-family:monospace;"></textarea>
		<p><button class="button button-primary">Import</button></p>';
	wp_nonce_field( 'dcsx_imp' );
	echo '</form>

	<h2>Laatste import log</h2>
	<pre style="
		background:#f7f7f7;border:1px solid #ccd0d4;padding:10px;
		white-space:pre-wrap;max-height:600px;overflow:auto;resize:vertical;">'
		. esc_html( json_encode( $old, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) )
		. '</pre></div>';
}

/*──────────────────────── 4. Metaboxen ─────────────────────────────────────────*/
add_action( 'add_meta_boxes', function(){

	/*──────── Slider metabox ───────*/
	add_meta_box( 'dcsx_slider_meta', 'NX Slider‑instellingen', function( $post ){

		wp_nonce_field( 'dcsx_s', 'dcsx_s_nonce' );
		$s = get_post_meta( $post->ID, '_dcsx_slider', true );
		if ( ! is_array( $s ) ) $s = dcsx_default_slider();
		if ( empty( $s['vector_px'] ) ) $s['vector_px'] = $s['breedte'] / 200;
		$col = dcsx_colors();
?>
<style>
.dcsxT{border-collapse:collapse;width:100%}
.dcsxT th,.dcsxT td{padding:6px 8px;border-bottom:1px solid #e5e8ee;font-size:13px}
.dcsxT input[type=text],.dcsxT input[type=number]{width:120px}
.dcsxT textarea{width:120px;height:28px;font:11px monospace}
</style>

<table class="dcsxT">
 <tr><th>Label / titel</th><td><input name="dcsx[label]" value="<?=esc_attr($s['label'])?>"></td></tr>
 <tr><th>Woord links</th> <td><input name="dcsx[woord_links]" value="<?=esc_attr($s['woord_links'])?>"></td></tr>
 <tr><th>Woord rechts</th><td><input name="dcsx[woord_rechts]" value="<?=esc_attr($s['woord_rechts'])?>"></td></tr>
 <tr><th>Breedte (px)</th><td><input type="number" name="dcsx[breedte]" value="<?=$s['breedte']?>"></td></tr>
 <tr><th>Hoogte (px)</th><td><input type="number" name="dcsx[hoogte]" value="<?=$s['hoogte']?>"></td></tr>
 <tr><th>Vector‑px per 1</th><td><input type="number" step="0.01" name="dcsx[vector_px]" value="<?=$s['vector_px']?>"></td></tr>
 <tr><th>Gradient L/R</th><td>
   <input type="color" name="dcsx[gradient_left]"  value="<?=$s['gradient_left']?>">
   <input type="color" name="dcsx[gradient_right]" value="<?=$s['gradient_right']?>">
 </td></tr>
 <tr><th>Track outline</th><td><input type="color" name="dcsx[outline]" value="<?=$s['outline']?>"></td></tr>
 <tr><th>Markers tonen</th><td><input type="checkbox" name="dcsx[markers_aan]" value="1" <?=empty($s['markers_aan'])?'':'checked'?>></td></tr>
</table>

<h4>Knoppen</h4>
<table class="dcsxT" id="btnT">
 <thead><tr><th>Naam</th><th>SVG</th><th>Kleur</th><th>Outl.</th><th>Mapping</th><th>Extra vectors</th><th></th></tr></thead>
 <tbody>
<?php foreach ( $s['buttons'] as $i=>$b ): ?>
<tr>
 <td><input name="dcsx[buttons][<?=$i?>][naam]" value="<?=esc_attr($b['naam'])?>"></td>
 <td><textarea name="dcsx[buttons][<?=$i?>][icoon_svg]"><?=esc_textarea($b['icoon_svg'])?></textarea></td>
 <td><input type="color" name="dcsx[buttons][<?=$i?>][knopkleur]" value="<?=$b['knopkleur']?>"></td>
 <td><input type="color" name="dcsx[buttons][<?=$i?>][knop_outline]" value="<?=$b['knop_outline']?>"></td>
 <td>
  <details><summary style="cursor:pointer;font-size:12px">mapping</summary>
   <table class="dcsxT">
<?php foreach ( $col as $c ): $m=$b['mapping'][$c]; ?>
    <tr>
     <td><?=ucfirst($c)?></td>
     <td><input name="dcsx[buttons][<?=$i?>][mapping][<?=$c?>][veld]" value="<?=esc_attr($m['veld'])?>" style="width:140px"></td>
     <td><input type="number" name="dcsx[buttons][<?=$i?>][mapping][<?=$c?>][vector_hoek]" value="<?=$m['vector_hoek']?>" style="width:60px"></td>
     <td><input type="number" step="any" name="dcsx[buttons][<?=$i?>][mapping][<?=$c?>][vector_lengte]" value="<?=$m['vector_lengte']?>" style="width:60px"></td>
     <td><input type="color" name="dcsx[buttons][<?=$i?>][mapping][<?=$c?>][kleur]" value="<?=$m['kleur']?>"></td>
     <td><input type="color" name="dcsx[buttons][<?=$i?>][mapping][<?=$c?>][outline]" value="<?=$m['outline']?>"></td>
    </tr>
<?php endforeach; ?>
   </table>
  </details>
 </td>
 <td>
  <details><summary style="cursor:pointer;font-size:12px">extra</summary>
   <table class="dcsxT vecT">
<?php $vec=$b['extra_vectors']?:[['naam'=>'','hoek'=>'','lengte'=>'','kleur'=>'','outline'=>'']];
foreach ( $vec as $j=>$v ): ?>
    <tr>
     <td><input name="dcsx[buttons][<?=$i?>][extra_vectors][<?=$j?>][naam]"    value="<?=esc_attr($v['naam'])?>" style="width:110px"></td>
     <td><input type="number" name="dcsx[buttons][<?=$i?>][extra_vectors][<?=$j?>][hoek]"   value="<?=$v['hoek']?>" style="width:55px"></td>
     <td><input type="number" name="dcsx[buttons][<?=$i?>][extra_vectors][<?=$j?>][lengte]" value="<?=$v['lengte']?>" style="width:55px"></td>
     <td><input type="color"  name="dcsx[buttons][<?=$i?>][extra_vectors][<?=$j?>][kleur]"  value="<?=$v['kleur']?:'#cccccc'?>"></td>
     <td><input type="color"  name="dcsx[buttons][<?=$i?>][extra_vectors][<?=$j?>][outline]"value="<?=$v['outline']?:'#000000'?>"></td>
    </tr>
<?php endforeach; ?>
   </table>
   <button type="button" class="button vAdd" data-i="<?=$i?>">+ Vector</button>
  </details>
 </td>
 <td><button type="button" class="button rmBtn">−</button></td>
</tr>
<?php endforeach; ?>
 </tbody>
</table>
<button type="button" class="button addBtn">+ Knop</button>

<script>
(()=>{const tb=document.querySelector('#btnT tbody');
document.querySelector('.addBtn').onclick=()=>{
 const last=tb.querySelector('tr:last-child');const clone=last.cloneNode(true);const idx=tb.children.length;
 clone.querySelectorAll('[name]').forEach(n=>n.name=n.name.replace(/\[buttons]\[\d+]/,`[buttons][${idx}]`));
 clone.querySelectorAll('input,textarea').forEach(el=>{if(el.type!=='color')el.value='';});
 tb.appendChild(clone);
};
tb.onclick=e=>{
 if(e.target.classList.contains('rmBtn')&&tb.children.length>1)
  e.target.closest('tr').remove();
 if(e.target.classList.contains('vAdd')){
  const i=e.target.dataset.i;const tbl=e.target.previousElementSibling;
  const row=tbl.querySelector('tr:last-child').cloneNode(true);
  const next=tbl.querySelectorAll('tr').length;
  row.querySelectorAll('[name]').forEach(n=>n.name=n.name.replace(/\[extra_vectors]\[\d+]/,`[extra_vectors][${next}]`));
  row.querySelectorAll('input').forEach(el=>{if(el.type!=='color')el.value='';});
  tbl.appendChild(row);
 }
};
})();
</script>
<?php
	}, 'dcsx_slider', 'normal', 'high' );

	/*──────── Group metabox ───────*/
	add_meta_box( 'dcsx_group_meta', 'NX Groep‑instellingen', function( $post ){

		wp_nonce_field( 'dcsx_g', 'dcsx_g_nonce' );
		$g   = get_post_meta( $post->ID, '_dcsx_group', true );
		if ( ! is_array( $g ) ) $g = dcsx_default_group();
		$all = get_posts( [ 'post_type'=>'dcsx_slider','numberposts'=>-1 ] );
?>
<style>.dcsxT th{width:220px}</style>

<h4>Sliders in deze groep</h4>
<table class="dcsxT" id="slT"><tbody>
<?php foreach ( $g['sliders'] ?: [''] as $sid ): ?>
<tr><td><select name="dcsg[sliders][]"><option value="">—</option>
<?php foreach ( $all as $s ) echo '<option value="'.$s->ID.'"'.selected($sid,$s->ID,false).'>'.esc_html($s->post_title).'</option>'; ?>
</select></td><td><button type="button" class="button rmSl">−</button></td></tr>
<?php endforeach; ?>
</tbody></table>
<button type="button" class="button addSl">+ Slider</button>

<h4>Padding tussen sliders (px)</h4>
<input type="number" name="dcsg[padding]" value="<?=$g['padding']?>">

<h4>Opties</h4>
<label><input type="checkbox" name="dcsg[bolletjes_overlappen]" value="1" <?=empty($g['bolletjes_overlappen'])?'':'checked'?>> bolletjes mogen overlappen</label><br>
<label><input type="checkbox" name="dcsg[sliders_overlappen]" value="1" <?=empty($g['sliders_overlappen'])?'':'checked'?>> sliders exact over elkaar</label>

<h4>Bézier‑stijlen</h4>
<table class="dcsxT" id="bzT">
 <thead><tr><th>Naam</th><th>Toon</th><th>Kleur</th><th>Dikte</th><th></th></tr></thead>
 <tbody>
<?php foreach ( $g['beziers'] as $name=>$b ): ?>
<tr>
 <td><input name="dcsg[beziers][<?=esc_attr($name)?>][label]" value="<?=esc_attr($name)?>" style="width:120px"></td>
 <td><input type="checkbox" name="dcsg[beziers][<?=esc_attr($name)?>][show]" value="1" <?=empty($b['show'])?'':'checked'?>></td>
 <td><input type="color" name="dcsg[beziers][<?=esc_attr($name)?>][color]" value="<?=esc_attr($b['color'])?>"></td>
 <td><input type="number" step="0.1" name="dcsg[beziers][<?=esc_attr($name)?>][dikte]" value="<?=esc_attr($b['dikte'])?>"></td>
 <td><button type="button" class="button rmBz">−</button></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<button type="button" class="button addBz">+ Bézier</button>

<script>
(()=>{const slT=document.querySelector('#slT tbody');
document.querySelector('.addSl').onclick=()=>{const r=slT.children[0].cloneNode(true);r.querySelector('select').selectedIndex=0;slT.appendChild(r);};
slT.onclick=e=>{if(e.target.classList.contains('rmSl')&&slT.children.length>1)e.target.closest('tr').remove();};

const bzT=document.querySelector('#bzT tbody');
document.querySelector('.addBz').onclick=()=>{
 const id='n'+Date.now();const r=document.createElement('tr');
 r.innerHTML='<td><input name="dcsg[beziers]['+id+'][label]" style="width:120px"></td>'
            +'<td><input type="checkbox" name="dcsg[beziers]['+id+'][show]" value="1" checked></td>'
            +'<td><input type="color" name="dcsg[beziers]['+id+'][color]" value="#444444"></td>'
            +'<td><input type="number" step="0.1" name="dcsg[beziers]['+id+'][dikte]" value="2.8"></td>'
            +'<td><button type="button" class="button rmBz">−</button></td>';
 bzT.appendChild(r);
};
bzT.onclick=e=>{if(e.target.classList.contains('rmBz')&&bzT.children.length>1)e.target.closest('tr').remove();};
})();
</script>
<?php
	}, 'dcsx_slider_group', 'normal', 'high' );
});

/*──────────────────────── 5. Save‑hooks (arrays direct) ───────────────────────*/
add_action( 'save_post_dcsx_slider', function( $id ){
	if ( wp_is_post_revision( $id ) ) return;
	if ( ! isset( $_POST['dcsx'] ) || ! check_admin_referer( 'dcsx_s', 'dcsx_s_nonce', false ) ) return;
	update_post_meta( $id, '_dcsx_slider', $_POST['dcsx'] );
}, 10, 1 );

add_action( 'save_post_dcsx_slider_group', function( $id ){
	if ( wp_is_post_revision( $id ) ) return;
	if ( ! isset( $_POST['dcsg'] ) || ! check_admin_referer( 'dcsx_g', 'dcsx_g_nonce', false ) ) return;
	$g = $_POST['dcsg'];
	$bez=[];
	foreach ( $g['beziers'] ?? [] as $row ){
		$n = trim( $row['label'] ?? '' ); unset( $row['label'] );
		if ( $n !== '' ) $bez[$n]=$row;
	}
	$g['beziers']=$bez;
	update_post_meta( $id, '_dcsx_group', $g );
}, 10, 1 );

/*──────────────────────── 6. Shortcodes & HEX‑helper ──────────────────────────*/
add_shortcode( 'dcsx_slider',        fn( $a ) => dcsx_renderer::single( absint( $a['id'] ?? 0 ) ) );
add_shortcode( 'dcsx_slider_group',  fn( $a ) => dcsx_renderer::group( absint( $a['id'] ?? 0 ) ) );

add_action( 'admin_footer', function(){
	$s=get_current_screen();
	if ( ! $s || ! in_array( $s->post_type, [ 'dcsx_slider','dcsx_slider_group' ], true ) ) return;
?>
<script>
document.querySelectorAll('input[type="color"]:not([data-dcsx])').forEach(c=>{
 c.dataset.dcsx=1;
 const t=document.createElement('input');t.type='text';t.size=7;t.value=c.value;t.style.marginLeft='6px';
 c.after(t);c.addEventListener('input',()=>t.value=c.value);
 t.addEventListener('input',()=>{const v=t.value.startsWith('#')?t.value:'#'+t.value;if(/^#[0-9a-fA-F]{6}$/.test(v))c.value=v;});
});
</script>
<?php
}, 20 );
