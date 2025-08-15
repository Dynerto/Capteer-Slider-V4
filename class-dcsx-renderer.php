<?php
/*
 * class‑dcsx‑renderer.php  – v3.5.4 – 15 Aug 2025 – o3
 * Renderer voor Dynerto Coupled Slider NX
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class dcsx_renderer {

	/* vaste kleurvolgorde */
	private static array $seq = [ 'paars','rood','blauw','oranje','groen','geel','zilver' ];

	/* layout‑constanten */
	private const LABEL_W   = 140;   // px
	private const GAP_LABEL = 12;    // px
	private const TRACK_SH  = 18;    // min‑hoogte track
	private const KNOB_D    = 28;    // diameter standaard‑knop

	/* runtime: totale rij‑breedte → nodig voor centrering SVG (groeps‑breed) */
	private static int $ROW_W = 0;

	/*────────────────── 1. Publieke API ─────────────────────*/

	public static function single( int $id ) : string {
		$m = get_post_meta( $id, '_dcsx_slider', true );
		$S = is_array( $m ) ? $m : json_decode( $m, true );
		if ( ! $S ) return '';
		$dummy = [ 'padding'=>0,'bolletjes_overlappen'=>0,'sliders_overlappen'=>0,'beziers'=>[] ];
		return self::slider( $S, $dummy, 0 );
	}

	public static function group( int $id ) : string {

		$m = get_post_meta( $id, '_dcsx_group', true );
		$G = is_array( $m ) ? $m : json_decode( $m, true );
		if ( ! $G || empty( $G['sliders'] ) ) return '';

		/* ── Fase 1: sliders inlezen + max. rijbreedte bepalen ── */
		$sliders = [];
		$maxRowW = 0;

		foreach ( $G['sliders'] as $sid ) {
			$sm = get_post_meta( $sid, '_dcsx_slider', true );
			$S  = is_array( $sm ) ? $sm : json_decode( $sm, true );
			if ( ! $S ) continue;

			$W     = intval( $S['breedte'] );
			$rowW  = self::LABEL_W*2 + self::GAP_LABEL*2 + $W;
			$maxRowW = max( $maxRowW, $rowW );

			$sliders[] = $S;
		}
		if ( empty( $sliders ) ) return '';

		/* Voor deze groep één gedeelde canvas‑breedte vastzetten */
		self::$ROW_W = $maxRowW;

		/* ── Fase 2: renderen + padpunten verzamelen ── */
		$out   = '<div class="dcs-group" style="position:relative">';
		$paths = [];              // knopnaam ⇒ punten
		$y     = 0;               // verticale offset

		foreach ( $sliders as $S ) {

			$out .= self::slider( $S, $G, $y, $paths );

			$hBox   = max( $S['hoogte'], self::TRACK_SH ) + 20;
			$labelH = $S['label'] ? 22 : 0;
			$y     += $labelH + $hBox
			        + ( empty( $G['sliders_overlappen'] ) ? intval( $G['padding'] ) : 0 );
		}

		/* ── Bézier‑overlays tekenen ── */
		foreach ( $G['beziers'] ?? [] as $name => $st ) {
			if ( empty( $st['show'] ) || empty( $paths[$name] ) || count($paths[$name])<2 ) continue;
			$st = array_replace( [ 'color'=>'#444','dikte'=>2.5 ], $st );
			$out .= self::spline_svg( $paths[$name], $st['color'], $st['dikte'] );
		}

		return $out . '</div>';
	}

	/*────────────────── 2. Slider ───────────────────────────*/

	private static function slider( array $S, array $G, int $yOff, array &$paths=[] ) : string {

		$W      = intval( $S['breedte'] );
		$Htrack = max( intval( $S['hoogte'] ), self::TRACK_SH );
		$vecPx  = floatval( $S['vector_px'] ?: 1.1 );

		/* rijbreedte van deze slider (zonder globale state wijzigen) */
		$rowW    = self::LABEL_W*2 + self::GAP_LABEL*2 + $W;
		$labelH  = $S['label'] ? 22 : 0;
		$boxH    = $Htrack + 20;
		$centerY = $yOff + $labelH + $boxH/2;

		/* linker offset binnen de rij + horizontale correctie naar groepscanvas */
		$trackLeft = self::LABEL_W + self::GAP_LABEL;
		$offsetX   = self::$ROW_W > 0 ? ( self::$ROW_W - $rowW ) / 2 : 0;

		$html = '<div style="margin-bottom:' . ( empty($G['sliders_overlappen']) ? intval($G['padding']) : 0 ) . 'px">';
		if ( $S['label'] )
			$html .= '<div style="font-weight:600;text-align:center;margin-bottom:4px">'
			       . esc_html($S['label']) . '</div>';

		$html .= '<div style="display:flex;align-items:center;justify-content:center">';

		$html .= '<span style="width:' . self::LABEL_W . 'px;text-align:right;padding-right:'
		       . self::GAP_LABEL . 'px;">' . esc_html($S['woord_links']) . '</span>';

		$html .= '<div style="position:relative;width:' . $W . 'px;height:' . $boxH . 'px;">';
		$html .= '<div style="width:' . $W . 'px;height:' . $Htrack . 'px;border-radius:10px;position:absolute;left:0;top:50%;transform:translateY(-50%);'
		       . 'background:linear-gradient(90deg,' . $S['gradient_left'] . ',' . $S['gradient_right'] . ');'
		       . 'box-shadow:0 0 0 3px ' . $S['outline'] . ';"></div>';

		foreach ( $S['buttons'] as $idx => $B ) {

			$pct   = self::pct( $B );
			$xAbs  = $offsetX + $trackLeft + $pct/100 * $W;   // groeps‑coördinaat
			$name  = trim( $B['naam'] ?? '' ) ?: 'knop'.$idx; // naam match robuuster
			$paths[$name][] = [ $xAbs, $centerY ];

			$html .= '<span style="
				position:absolute;left:' . $pct . '%;top:50%;
				transform:translate(-50%,-50%) translateY(1px); /* perfect vert. centrering */
				z-index:90;">' . self::knob($B) . '</span>';

			if ( ! empty( $S['markers_aan'] ) ) {
				foreach ( self::vectors($B) as [ $deg,$len,$fc,$oc ] ) {
					$r = abs($len) * $vecPx;
					if ( $r==0 && empty($G['bolletjes_overlappen']) ) continue;
					$dx =  cos(deg2rad($deg))*$r;
					$dy = -sin(deg2rad($deg))*$r;
					$html .= '<span style="
						position:absolute;left:' . $pct . '%;top:50%;
						width:' . (2*$r) . 'px;height:' . (2*$r) . 'px;margin:-' . $r . 'px;
						border-radius:50%;background:' . ($fc ?: '#ccc') . ';
						border:2px solid ' . ($oc ?: '#000') . ';
						transform:translate(' . $dx . 'px,' . $dy . 'px);z-index:' . (10+$idx) . '"></span>';
				}
			}
		}

		$html .= '</div>';

		$html .= '<span style="width:' . self::LABEL_W . 'px;padding-left:'
		       . self::GAP_LABEL . 'px;">' . esc_html($S['woord_rechts']) . '</span>';

		$html .= '</div></div>';
		return $html;
	}

	/*────────────────── 3. Hulproutines ─────────────────────*/

	private static function vectors( array $B ) : array {
		$v=[];
		foreach ( self::$seq as $c ){
			$m=$B['mapping'][$c]??[];
			$v[]=[ $m['vector_hoek']??0, self::val($m),
			       $m['kleur']??null, $m['outline']??null ];
		}
		foreach( $B['extra_vectors']??[] as $e )
			$v[]=[ $e['hoek'],$e['lengte'],$e['kleur']??null,$e['outline']??null ];
		return $v;
	}
	private static function val( array $m ):float{
		if ( ! empty($m['veld']) ){
			$v = do_shortcode( $m['veld'] );
			if ( is_numeric($v) ) return (float)$v;
		}
		return floatval($m['vector_lengte']??1);
	}
	private static function pct( array $B ):float{
		$sum=$w=0;
		foreach( self::vectors($B) as [$deg,$len] ){
			$a=deg2rad($deg);
			$sum+=cos($a)*$len;
			$w  +=abs(cos($a))*abs($len)+0.15;
		}
		return $w?max(0,min(100,50+$sum/$w*50)):50;
	}
	private static function knob( array $B ):string{
		$svg=trim($B['icoon_svg']??'');
		return $svg!==''?$svg
			:'<svg width="'.self::KNOB_D.'" height="'.self::KNOB_D.'" viewBox="0 0 24 24">'
			.'<circle cx="12" cy="12" r="9" fill="'.esc_attr($B['knopkleur'])
			.'" stroke="'.esc_attr($B['knop_outline']).'" stroke-width="2.2"/></svg>';
	}

	/*──── Catmull–Rom → cubic Bézier pad ───*/
	private static function spline_svg( array $p, string $col, float $w ): string {

		$n = count($p);
		if ( $n < 2 ) return '';

		$path = 'M' . $p[0][0] . ' ' . $p[0][1];

		for ( $i = 0; $i < $n - 1; $i++ ) {
			$p0 = $p[ max(0, $i-1) ];
			$p1 = $p[ $i ];
			$p2 = $p[ $i+1 ];
			$p3 = $p[ min($n-1, $i+2) ];

			$cx1 = $p1[0] + ( $p2[0] - $p0[0] ) / 6;
			$cy1 = $p1[1] + ( $p2[1] - $p0[1] ) / 6;
			$cx2 = $p2[0] - ( $p3[0] - $p1[0] ) / 6;
			$cy2 = $p2[1] - ( $p3[1] - $p1[1] ) / 6;

			$path .= " C$cx1 $cy1 $cx2 $cy2 {$p2[0]} {$p2[1]}";
		}

		return '<svg style="position:absolute;top:0;left:50%;transform:translateX(-50%);'
		     . 'width:' . self::$ROW_W . 'px;height:100%;pointer-events:none;z-index:40">'
		     . '<path d="' . $path . '" stroke="' . esc_attr($col)
		     . '" stroke-width="' . esc_attr($w)
		     . '" fill="none" stroke-linecap="round"/></svg>';
	}
}
