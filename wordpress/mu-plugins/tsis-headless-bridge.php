<?php
/**
 * Plugin Name: TSIS — Pont de contenu headless
 * Description: Expose le contenu éditorial de TSIS (options, pages, CPT service/realisation/tsis_member, taxonomie secteur, menus) à l'API REST pour le site Astro, via la route GET /wp-json/tsis/v1/content.
 * Version: 1.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: TSIS
 * License: GPL-2.0-or-later
 * Text Domain: tsis-headless-bridge
 *
 * Greffon « must-use » autonome : aucune dépendance externe.
 * ACF est FACULTATIF — toutes les valeurs sont lues via get_option() / get_post_meta(),
 * ce qui rend le pont compatible avec ACF comme avec des métadonnées natives saisies à la main.
 *
 * Contrat de référence : docs/contrat-contenu-wp.md (§4 route, §5 modèle, §6 livrables).
 * Ce fichier n'écrit jamais de contenu : une donnée absente donne null ou [], jamais une
 * valeur inventée. Il ne peut jamais casser le site : toute erreur est capturée et rendue
 * en JSON lisible (sans trace PHP ni requête SQL).
 *
 * @package TSIS\HeadlessBridge
 */

// Sécurité : chargé plusieurs fois (mu-plugins + plugins) → on ne définit les fonctions qu'une fois.
if ( defined( 'TSIS_BRIDGE_LOADED' ) ) {
	return;
}
define( 'TSIS_BRIDGE_LOADED', true );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Pas d'accès direct au fichier.
}

define( 'TSIS_BRIDGE_VERSION', '1.0.0' );
define( 'TSIS_BRIDGE_CACHE_TTL', 300 ); // Secondes (aligné sur Cache-Control: max-age).

/* -------------------------------------------------------------------------
 * 1. Enregistrements : CPT, menus, métadonnées, options
 * ---------------------------------------------------------------------- */

/**
 * Enregistre le CPT `tsis_member` (§5.8).
 * Le CPT n'a pas de vue publique : il alimente uniquement le pont et l'admin.
 */
function tsis_bridge_register_post_type() {
	register_post_type(
		'tsis_member',
		array(
			'labels'             => array(
				'name'          => 'Équipe',
				'singular_name' => 'Membre de l’équipe',
				'menu_name'     => 'Équipe TSIS',
				'add_new_item'  => 'Ajouter un membre',
				'edit_item'     => 'Modifier un membre',
				'all_items'     => 'Tous les membres',
				'search_items'  => 'Rechercher un membre',
				'not_found'     => 'Aucun membre',
			),
			'description'        => 'Membres de l’équipe TSIS (exposés par le pont tsis/v1/content).',
			'public'             => false,       // Pas de page publique dédiée.
			'publicly_queryable' => false,
			'show_ui'            => true,        // Éditable dans l'administration.
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'menu_icon'          => 'dashicons-groups',
			'supports'           => array( 'title', 'thumbnail', 'page-attributes', 'custom-fields' ),
			'has_archive'        => false,
			'rewrite'            => false,
			'query_var'          => false,
			'hierarchical'       => false,
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
		)
	);
}

/**
 * Déclare les emplacements de menus (§5.2) : tsis_primary et tsis_footer.
 * Hooké après le thème pour ne pas entrer en conflit avec hello-elementor.
 */
function tsis_bridge_register_nav_menus() {
	register_nav_menus(
		array(
			'tsis_primary' => 'TSIS — Navigation principale',
			'tsis_footer'  => 'TSIS — Navigation de pied de page',
		)
	);
}

/**
 * Enregistre une métadonnée d'article exposée en REST (compatibilité éditeur).
 *
 * @param string $post_type Type d'article.
 * @param array  $names     Noms de métadonnées.
 * @param string $type      Type de valeur ('string', 'boolean', 'integer', 'number').
 */
function tsis_bridge_register_post_meta_list( $post_type, $names, $type = 'string' ) {
	foreach ( $names as $name ) {
		register_post_meta(
			$post_type,
			$name,
			array(
				'type'          => $type,
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
}

/**
 * Enregistre une métadonnée de type tableau (relation ACF, galerie) avec un schéma REST explicite.
 *
 * @param string $post_type Type d'article.
 * @param string $name      Nom de la métadonnée.
 */
function tsis_bridge_register_post_meta_array( $post_type, $name ) {
	register_post_meta(
		$post_type,
		$name,
		array(
			'type'          => 'array',
			'single'        => true,
			'show_in_rest'  => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array( 'type' => array( 'string', 'integer' ) ),
				),
			),
			'auth_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}

/**
 * Enregistre toutes les métadonnées utiles en REST (§6.1).
 * Les clés sont EXACTEMENT celles du contrat §5 (mêmes noms que les champs ACF).
 */
function tsis_bridge_register_meta() {
	// Page d'accueil + page À propos + en-têtes d'index (stockés en métadonnées de page).
	tsis_bridge_register_post_meta_list(
		'page',
		array(
			// §5.3 Accueil — Hero.
			'tsis_hero_eyebrow',
			'tsis_hero_title',
			'tsis_hero_text',
			'tsis_hero_image',
			'tsis_hero_image_alt',
			'tsis_hero_primary_cta_label',
			'tsis_hero_primary_cta_href',
			'tsis_hero_secondary_cta_label',
			'tsis_hero_secondary_cta_href',
			// §5.3 Accueil — À propos.
			'tsis_home_about_eyebrow',
			'tsis_home_about_title',
			'tsis_home_about_text',
			'tsis_home_about_note',
			'tsis_home_about_image',
			'tsis_home_about_image_alt',
			'tsis_home_about_cta_label',
			'tsis_home_about_cta_href',
			// §5.3 Accueil — Solutions.
			'tsis_home_solutions_eyebrow',
			'tsis_home_solutions_title',
			'tsis_home_solutions_intro',
			'tsis_home_solutions_cta_label',
			'tsis_home_solutions_cta_href',
			// §5.3 Accueil — Services.
			'tsis_home_services_eyebrow',
			'tsis_home_services_title',
			'tsis_home_services_intro',
			'tsis_home_services_cta_label',
			'tsis_home_services_cta_href',
			// §5.3 Accueil — Pourquoi.
			'tsis_home_why_eyebrow',
			'tsis_home_why_title',
			'tsis_home_why_intro',
			'tsis_home_why_cta_label',
			'tsis_home_why_cta_href',
			// §5.3 Accueil — Processus.
			'tsis_home_process_eyebrow',
			'tsis_home_process_title',
			'tsis_home_process_intro',
			// §5.3 Accueil — CTA final.
			'tsis_home_final_eyebrow',
			'tsis_home_final_title',
			'tsis_home_final_text',
			'tsis_home_final_cta_label',
			'tsis_home_final_cta_href',
			// §5.4 Page À propos — en-tête.
			'tsis_about_eyebrow',
			'tsis_about_title',
			'tsis_about_intro',
			'tsis_about_badge',
			// §5.4 Page À propos — expertise + équipe (titre de section).
			'tsis_about_expertise_eyebrow',
			'tsis_about_expertise_title',
			'tsis_about_team_eyebrow',
			'tsis_about_team_title',
			'tsis_about_team_intro',
			// §5.9 En-têtes d'index, stockés en option mais surchargeables par la page.
			'tsis_ui_services_index_eyebrow',
			'tsis_ui_services_index_title',
			'tsis_ui_services_index_intro',
			'tsis_ui_solutions_index_eyebrow',
			'tsis_ui_solutions_index_title',
			'tsis_ui_solutions_index_intro',
			'tsis_ui_contact_hero_eyebrow',
			'tsis_ui_contact_hero_title',
			'tsis_ui_contact_hero_intro',
		)
	);

	// Répéteurs/textes de page stockés par ACF sous forme de compteur (valeur scalaire).
	tsis_bridge_register_post_meta_list(
		'page',
		array(
			'tsis_hero_chips',
			'tsis_home_about_highlights',
			'tsis_home_why_items',
			'tsis_home_process_steps',
			'tsis_about_pillars',
			'tsis_about_expertise_paragraphs',
			'tsis_about_expertise_countries',
			'tsis_about_stats',
		)
	);
	// Relation vers realisation (tableau d'identifiants/slugs).
	tsis_bridge_register_post_meta_array( 'page', 'tsis_home_solutions_featured' );

	// CPT service (§5.5).
	tsis_bridge_register_post_meta_list(
		'service',
		array(
			'tsis_service_icon',
			'tsis_service_body',
			'tsis_service_seo_title',
			'tsis_service_seo_description',
		)
	);
	tsis_bridge_register_post_meta_list( 'service', array( 'tsis_service_features' ) );

	// CPT realisation (§5.6).
	tsis_bridge_register_post_meta_list(
		'realisation',
		array(
			'tsis_realisation_client',
			'tsis_realisation_year',
			'tsis_realisation_body',
			'tsis_realisation_seo_title',
			'tsis_realisation_seo_description',
		)
	);
	tsis_bridge_register_post_meta_list( 'realisation', array( 'tsis_realisation_results' ) );
	tsis_bridge_register_post_meta_array( 'realisation', 'tsis_realisation_gallery' );

	// CPT tsis_member (§5.8).
	tsis_bridge_register_post_meta_list( 'tsis_member', array( 'tsis_member_role', 'tsis_member_photo' ) );
	tsis_bridge_register_post_meta_list( 'tsis_member', array( 'tsis_member_visible' ), 'boolean' );
}

/**
 * Enregistre les options globales en REST (§5.1, §5.2, §5.9).
 * Le pont lit de toute façon les valeurs via get_option() : cet enregistrement sert
 * uniquement à rendre ces champs éditables via l'API des réglages.
 */
function tsis_bridge_register_options() {
	$options = array(
		// §5.1 Identité / contact.
		'tsis_site_name',
		'tsis_site_short_name',
		'tsis_site_legal_name',
		'tsis_site_tagline',
		'tsis_site_description',
		'tsis_site_logo',
		'tsis_site_url',
		'tsis_contact_email',
		'tsis_contact_address',
		'tsis_contact_maps_url',
		'tsis_contact_whatsapp',
		// §5.2 Bouton principal d'en-tête.
		'tsis_primary_cta_label',
		'tsis_primary_cta_href',
		// §5.9 Libellés d'interface.
		'tsis_ui_project_eyebrow',
		'tsis_ui_project_title',
		'tsis_ui_service_aside_title',
		'tsis_ui_service_aside_text',
		'tsis_ui_service_aside_cta_label',
		'tsis_ui_realisation_aside_title',
		'tsis_ui_realisation_aside_text',
		'tsis_ui_realisation_aside_cta_label',
		'tsis_ui_related_services_title',
		'tsis_ui_related_realisations_title',
		'tsis_ui_discover_label',
		'tsis_ui_contact_hero_eyebrow',
		'tsis_ui_contact_hero_title',
		'tsis_ui_contact_hero_intro',
		'tsis_ui_services_index_eyebrow',
		'tsis_ui_services_index_title',
		'tsis_ui_services_index_intro',
		'tsis_ui_solutions_index_eyebrow',
		'tsis_ui_solutions_index_title',
		'tsis_ui_solutions_index_intro',
	);

	foreach ( $options as $name ) {
		register_setting(
			'tsis_content',
			$name,
			array(
				'type'          => 'string',
				'show_in_rest'  => true,
				'default'       => '',
				'auth_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}
}

/**
 * Déclare la page d'options ACF (si ACF PRO est présent) qui accueille les
 * groupes de champs « Réglages du site » et « Libellés d'interface ».
 * Sans ACF PRO, le pont fonctionne quand même : les valeurs sont lues via get_option().
 */
function tsis_bridge_maybe_register_options_page() {
	if ( function_exists( 'acf_add_options_page' ) ) {
		acf_add_options_page(
			array(
				'page_title' => 'Contenu du site TSIS',
				'menu_title' => 'Contenu TSIS',
				'menu_slug'  => 'tsis-content-options',
				'capability' => 'edit_posts',
				'redirect'   => false,
				'position'   => '58.9',
				'icon_url'   => 'dashicons-admin-generic',
			)
		);
	}
}

/* -------------------------------------------------------------------------
 * 2. Route REST
 * ---------------------------------------------------------------------- */

/**
 * Déclare la route GET /wp-json/tsis/v1/content (§4).
 */
function tsis_bridge_register_rest() {
	register_rest_route(
		'tsis/v1',
		'/content',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tsis_bridge_rest_content',
			'permission_callback' => '__return_true', // Lecture publique : le contenu l'est déjà.
			'args'                => array(
				'lang' => array(
					'type'        => 'string',
					'default'     => 'fr',
					'enum'        => array( 'fr', 'en' ),
					'description' => 'Langue demandée (fr ou en).',
				),
			),
		)
	);
}

/**
 * Callback de la route : assemble le document et pose les en-têtes attendus.
 * Toute exception est convertie en erreur 500 JSON lisible (jamais de trace PHP/SQL).
 *
 * @param WP_REST_Request $request Requête REST.
 * @return WP_REST_Response
 */
function tsis_bridge_rest_content( $request ) {
	$lang = (string) $request->get_param( 'lang' );
	if ( ! in_array( $lang, array( 'fr', 'en' ), true ) ) {
		$lang = 'fr';
	}

	try {
		$data     = tsis_bridge_build_document( $lang );
		$response = new WP_REST_Response( $data, 200 );
	} catch ( Throwable $e ) {
		// Message générique : aucune donnée sensible (ni SQL, ni chemin, ni trace).
		$response = new WP_REST_Response(
			array(
				'error'         => true,
				'code'          => 'tsis_bridge_error',
				'message'       => 'Le contenu n’a pas pu être assemblé. Réessayer plus tard.',
				'schemaVersion' => 1,
				'lang'          => $lang,
				'generatedAt'   => gmdate( 'c' ),
			),
			500
		);
	}

	$response->header( 'X-TSIS-Bridge', '1' );
	$response->header( 'Cache-Control', 'public, max-age=' . TSIS_BRIDGE_CACHE_TTL );

	return $response;
}

/**
 * Purge le cache du document (transient) à chaque modification de contenu.
 *
 * @param mixed $unused Argument passé par WordPress selon le hook (ignoré).
 */
function tsis_bridge_purge_cache( $unused = null ) {
	foreach ( array( 'fr', 'en' ) as $lang ) {
		delete_transient( 'tsis_content_' . $lang );
	}
}

/* -------------------------------------------------------------------------
 * 3. Helpers de lecture (compatibles ACF et métadonnées natives)
 * ---------------------------------------------------------------------- */

/**
 * Contexte de lecture : une publication précise.
 *
 * @param int $post_id Identifiant d'article.
 * @return array
 */
function tsis_bridge_ctx_post( $post_id ) {
	return array( 'post' => (int) $post_id );
}

/**
 * Contexte de lecture : les options globales.
 *
 * @return array
 */
function tsis_bridge_ctx_option() {
	return array( 'option' => true );
}

/**
 * Lit une valeur brute (scalaire ou tableau) depuis un contexte.
 *
 * @param array  $ctx Contexte issu de tsis_bridge_ctx_post()/tsis_bridge_ctx_option().
 * @param string $key Nom de la métadonnée ou de l'option.
 * @return mixed
 */
function tsis_bridge_raw( $ctx, $key ) {
	if ( isset( $ctx['post'] ) ) {
		return get_post_meta( (int) $ctx['post'], $key, true );
	}
	return get_option( $key, null );
}

/**
 * Normalise une valeur en chaîne non vide, ou null.
 * Un tableau/objet n'est jamais transformé en texte : il devient null.
 *
 * @param mixed $value Valeur brute.
 * @return string|null
 */
function tsis_bridge_str( $value ) {
	if ( is_array( $value ) || is_object( $value ) ) {
		return null;
	}
	if ( null === $value || false === $value ) {
		return null;
	}
	if ( is_bool( $value ) ) {
		return $value ? '1' : '0';
	}
	$value = trim( (string) $value );
	return ( '' === $value ) ? null : $value;
}

/**
 * Convertit une valeur en booléen (accepte 1/0, true/false, yes/on).
 *
 * @param mixed $value Valeur brute.
 * @return bool
 */
function tsis_bridge_bool( $value ) {
	if ( true === $value || 1 === $value ) {
		return true;
	}
	if ( is_string( $value ) ) {
		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
	return false;
}

/**
 * Transforme une URL du site en chemin relatif (§4) et conserve les URL externes.
 *
 * @param mixed $value URL brute.
 * @return string|null
 */
function tsis_bridge_href( $value ) {
	$url = tsis_bridge_str( $value );
	if ( null === $url ) {
		return null;
	}

	// Ancres, e-mail, téléphone : inchangés.
	if ( '#' === $url[0] || 0 === stripos( $url, 'mailto:' ) || 0 === stripos( $url, 'tel:' ) ) {
		return $url;
	}

	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) ) {
		return $url; // Déjà un chemin relatif.
	}

	$home = wp_parse_url( home_url( '/' ) );
	if ( ! empty( $home['host'] ) && 0 === strcasecmp( $home['host'], $parts['host'] ) ) {
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( ! empty( $parts['query'] ) ) {
			$path .= '?' . $parts['query'];
		}
		return $path;
	}

	return $url; // URL externe : absolue.
}

/**
 * Construit un CTA {label, href} ou null si les deux sont vides.
 *
 * @param mixed $label Libellé.
 * @param mixed $href  Lien.
 * @return array|null
 */
function tsis_bridge_cta( $label, $href ) {
	$label = tsis_bridge_str( $label );
	$href  = tsis_bridge_href( $href );
	if ( null === $label && null === $href ) {
		return null;
	}
	return array(
		'label' => $label,
		'href'  => $href,
	);
}

/**
 * Résout un identifiant de média à partir d'une valeur hétérogène.
 *
 * @param mixed $value Identifiant, tableau ACF, ou URL.
 * @return int 0 si aucune correspondance.
 */
function tsis_bridge_attachment_id( $value ) {
	if ( is_numeric( $value ) ) {
		$id = (int) $value;
		return ( $id > 0 ) ? $id : 0;
	}
	if ( is_array( $value ) ) {
		if ( isset( $value['ID'] ) ) {
			return (int) $value['ID'];
		}
		if ( isset( $value['id'] ) ) {
			return (int) $value['id'];
		}
		return 0;
	}
	if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
		$id = (int) attachment_url_to_postid( $value );
		return ( $id > 0 ) ? $id : 0;
	}
	return 0;
}

/**
 * Construit l'objet image {url, width, height, alt} depuis un identifiant de média.
 *
 * @param int $id Identifiant d'attachement.
 * @return array|null
 */
function tsis_bridge_image_from_id( $id ) {
	$id  = (int) $id;
	$src = wp_get_attachment_image_src( $id, 'full' );
	if ( ! $src || empty( $src[0] ) ) {
		return null;
	}

	$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	if ( '' === trim( $alt ) ) {
		$alt = (string) get_the_title( $id ); // Repli : le titre du média (jamais vide par défaut).
	}

	return array(
		'url'    => $src[0],
		'width'  => isset( $src[1] ) ? (int) $src[1] : 0,
		'height' => isset( $src[2] ) ? (int) $src[2] : 0,
		'alt'    => $alt,
	);
}

/**
 * Résout une valeur de média en objet image {url, width, height, alt} ou null.
 *
 * @param mixed $value Identifiant, tableau ACF, ou URL.
 * @return array|null
 */
function tsis_bridge_image( $value ) {
	$id = tsis_bridge_attachment_id( $value );
	if ( $id ) {
		return tsis_bridge_image_from_id( $id );
	}

	// Valeur déjà une URL absolue (métadonnée saisie à la main, ex. photo d'équipe).
	$url = tsis_bridge_str( $value );
	if ( null !== $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return array(
			'url'    => $url,
			'width'  => 0,
			'height' => 0,
			'alt'    => '',
		);
	}
	return null;
}

/**
 * Applique une conversion de type à une valeur de sous-champ.
 *
 * @param mixed  $value Valeur brute.
 * @param string $type  'str', 'href', 'bool' ou 'int'.
 * @return mixed
 */
function tsis_bridge_cast( $value, $type ) {
	switch ( $type ) {
		case 'bool':
			return tsis_bridge_bool( $value );
		case 'href':
			return tsis_bridge_href( $value );
		case 'int':
			return is_numeric( $value ) ? (int) $value : null;
		default:
			return tsis_bridge_str( $value );
	}
}

/**
 * Aplatit une ligne de répéteur selon une liste de sous-champs typés.
 *
 * @param array $row    Ligne brute (tableau associatif).
 * @param array $fields Map souschamp => type.
 * @return array
 */
function tsis_bridge_cast_row( $row, $fields ) {
	$out = array();
	foreach ( $fields as $name => $type ) {
		$out[ $name ] = tsis_bridge_cast( isset( $row[ $name ] ) ? $row[ $name ] : null, $type );
	}
	return $out;
}

/**
 * Aplatit un répéteur ACF (§6.1) ou une métadonnée native, en liste JSON propre.
 * Convention ACF : la valeur de base est le nombre de lignes, les sous-champs sont
 * stockés sous `base_<i>_<souschamp>`.
 *
 * @param array  $ctx    Contexte de lecture.
 * @param string $base   Nom du champ de base.
 * @param array  $fields Map souschamp => type.
 * @return array Liste (vide si rien).
 */
function tsis_bridge_repeater( $ctx, $base, $fields ) {
	$raw = tsis_bridge_raw( $ctx, $base );

	// Cas 1 : tableau natif déjà structuré.
	if ( is_array( $raw ) ) {
		$rows = array();
		foreach ( $raw as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = tsis_bridge_cast_row( $row, $fields );
			}
		}
		return $rows;
	}

	// Cas 2 : convention ACF — la valeur est le nombre de lignes.
	$count = is_numeric( $raw ) ? (int) $raw : 0;
	if ( $count <= 0 ) {
		return array();
	}

	$rows = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$row   = array();
		$empty = true;
		foreach ( $fields as $name => $type ) {
			$cell         = tsis_bridge_raw( $ctx, $base . '_' . $i . '_' . $name );
			$row[ $name ] = tsis_bridge_cast( $cell, $type );
			if ( null !== $row[ $name ] && '' !== $row[ $name ] && false !== $row[ $name ] ) {
				$empty = false;
			}
		}
		if ( ! $empty ) {
			$rows[] = $row;
		}
	}
	return $rows;
}

/**
 * Cherche la première sous-valeur non vide dont la clé commence par un préfixe.
 * Utilisé pour les répéteurs de texte dont le nom du sous-champ n'est pas imposé.
 *
 * @param array  $ctx    Contexte de lecture.
 * @param string $prefix Préfixe (ex. « tsis_hero_chips_0_ »).
 * @return string|null
 */
function tsis_bridge_scan_subfield( $ctx, $prefix ) {
	if ( isset( $ctx['post'] ) ) {
		$all = get_post_meta( (int) $ctx['post'] );
	} else {
		$all = wp_load_alloptions();
	}
	if ( ! is_array( $all ) ) {
		return null;
	}
	foreach ( $all as $key => $value ) {
		if ( 0 !== strpos( (string) $key, $prefix ) ) {
			continue;
		}
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		$value = maybe_unserialize( $value );
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		$value = tsis_bridge_str( $value );
		if ( null !== $value ) {
			return $value;
		}
	}
	return null;
}

/**
 * Aplatit un répéteur de texte (chips, highlights, paragraphes, pays) en liste de chaînes.
 *
 * @param array  $ctx  Contexte de lecture.
 * @param string $base Nom du champ.
 * @return array
 */
function tsis_bridge_text_list( $ctx, $base ) {
	$raw = tsis_bridge_raw( $ctx, $base );
	$out = array();

	// Tableau natif (scalaires ou lignes structurées).
	if ( is_array( $raw ) ) {
		foreach ( $raw as $row ) {
			if ( is_array( $row ) ) {
				foreach ( $row as $cell ) {
					$cell = tsis_bridge_str( $cell );
					if ( null !== $cell ) {
						$out[] = $cell;
						break;
					}
				}
			} else {
				$cell = tsis_bridge_str( $row );
				if ( null !== $cell ) {
					$out[] = $cell;
				}
			}
		}
		return $out;
	}

	// Convention ACF : nombre de lignes.
	$count = is_numeric( $raw ) ? (int) $raw : 0;
	for ( $i = 0; $i < $count; $i++ ) {
		$value = tsis_bridge_str( tsis_bridge_raw( $ctx, $base . '_' . $i . '_item' ) );
		if ( null === $value ) {
			$value = tsis_bridge_scan_subfield( $ctx, $base . '_' . $i . '_' );
		}
		if ( null !== $value ) {
			$out[] = $value;
		}
	}
	return $out;
}

/**
 * Résout une relation (ACF relationship ou native) en liste de slugs.
 *
 * @param array  $ctx Contexte de lecture.
 * @param string $key Nom du champ.
 * @return array
 */
function tsis_bridge_slug_list( $ctx, $key ) {
	$raw = tsis_bridge_raw( $ctx, $key );
	$out = array();

	$append = function ( $item ) use ( &$out ) {
		if ( is_numeric( $item ) ) {
			$slug = get_post_field( 'post_name', (int) $item );
			if ( $slug ) {
				$out[] = $slug;
			}
			return;
		}
		$value = tsis_bridge_str( $item );
		if ( null !== $value ) {
			$out[] = $value;
		}
	};

	if ( is_array( $raw ) ) {
		foreach ( $raw as $item ) {
			$append( $item );
		}
	} elseif ( is_string( $raw ) ) {
		foreach ( explode( ',', $raw ) as $item ) {
			$append( trim( $item ) );
		}
	}
	return $out;
}

/**
 * Aplatit une galerie ACF en liste d'objets image.
 *
 * @param array  $ctx Contexte de lecture.
 * @param string $key Nom du champ.
 * @return array
 */
function tsis_bridge_gallery( $ctx, $key ) {
	$raw = tsis_bridge_raw( $ctx, $key );
	$ids = array();

	if ( is_array( $raw ) ) {
		$ids = $raw;
	} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
		$maybe = maybe_unserialize( $raw );
		if ( is_array( $maybe ) ) {
			$ids = $maybe;
		} else {
			foreach ( explode( ',', $raw ) as $part ) {
				$part = trim( $part );
				if ( '' !== $part ) {
					$ids[] = $part;
				}
			}
		}
	}

	$out = array();
	foreach ( $ids as $id ) {
		$image = tsis_bridge_image( $id );
		if ( null !== $image ) {
			$out[] = $image;
		}
	}
	return $out;
}

/**
 * Récupère une page par son slug.
 *
 * @param string $slug Slug de page.
 * @return WP_Post|null
 */
function tsis_bridge_page( $slug ) {
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	return ( $page instanceof WP_Post ) ? $page : null;
}

/* -------------------------------------------------------------------------
 * 4. Construction des blocs du document (§5)
 * ---------------------------------------------------------------------- */

/**
 * §5.1 Identité du site.
 *
 * @return array
 */
function tsis_bridge_build_site() {
	return array(
		'name'        => tsis_bridge_str( get_option( 'tsis_site_name', null ) ),
		'shortName'   => tsis_bridge_str( get_option( 'tsis_site_short_name', null ) ),
		'legalName'   => tsis_bridge_str( get_option( 'tsis_site_legal_name', null ) ),
		'tagline'     => tsis_bridge_str( get_option( 'tsis_site_tagline', null ) ),
		'description' => tsis_bridge_str( get_option( 'tsis_site_description', null ) ),
		'logo'        => tsis_bridge_image( get_option( 'tsis_site_logo', null ) ),
		'url'         => tsis_bridge_str( get_option( 'tsis_site_url', null ) ),
		'email'       => tsis_bridge_str( get_option( 'tsis_contact_email', null ) ),
		'address'     => tsis_bridge_str( get_option( 'tsis_contact_address', null ) ),
		'mapsUrl'     => tsis_bridge_href( get_option( 'tsis_contact_maps_url', null ) ),
	);
}

/**
 * §5.1 Contact.
 *
 * @return array
 */
function tsis_bridge_build_contact() {
	return array(
		'phones'   => tsis_bridge_repeater(
			tsis_bridge_ctx_option(),
			'tsis_contact_phones',
			array(
				'label'    => 'str',
				'href'     => 'href',
				'whatsapp' => 'bool',
			)
		),
		'whatsapp' => tsis_bridge_href( get_option( 'tsis_contact_whatsapp', null ) ),
	);
}

/**
 * §5.1 Réseaux sociaux.
 *
 * @return array
 */
function tsis_bridge_build_social() {
	return tsis_bridge_repeater(
		tsis_bridge_ctx_option(),
		'tsis_social',
		array(
			'label' => 'str',
			'href'  => 'href',
		)
	);
}

/**
 * §5.9 Libellés d'interface.
 *
 * @return array
 */
function tsis_bridge_build_ui() {
	$map = array(
		'projectEyebrow'           => 'tsis_ui_project_eyebrow',
		'projectTitle'             => 'tsis_ui_project_title',
		'serviceAsideTitle'        => 'tsis_ui_service_aside_title',
		'serviceAsideText'         => 'tsis_ui_service_aside_text',
		'serviceAsideCtaLabel'     => 'tsis_ui_service_aside_cta_label',
		'realisationAsideTitle'    => 'tsis_ui_realisation_aside_title',
		'realisationAsideText'     => 'tsis_ui_realisation_aside_text',
		'realisationAsideCtaLabel' => 'tsis_ui_realisation_aside_cta_label',
		'relatedServicesTitle'     => 'tsis_ui_related_services_title',
		'relatedRealisationsTitle' => 'tsis_ui_related_realisations_title',
		'discoverLabel'            => 'tsis_ui_discover_label',
		'serviceHeroEyebrow'       => 'tsis_ui_service_hero_eyebrow',
		'heroCtaLabel'             => 'tsis_ui_hero_cta_label',
		'discoverServiceLabel'     => 'tsis_ui_discover_service_label',
		'discoverSolutionLabel'    => 'tsis_ui_discover_solution_label',
		'contactHeroEyebrow'       => 'tsis_ui_contact_hero_eyebrow',
		'contactHeroTitle'         => 'tsis_ui_contact_hero_title',
		'contactHeroIntro'         => 'tsis_ui_contact_hero_intro',
		'servicesIndexEyebrow'     => 'tsis_ui_services_index_eyebrow',
		'servicesIndexTitle'       => 'tsis_ui_services_index_title',
		'servicesIndexIntro'       => 'tsis_ui_services_index_intro',
		'solutionsIndexEyebrow'    => 'tsis_ui_solutions_index_eyebrow',
		'solutionsIndexTitle'      => 'tsis_ui_solutions_index_title',
		'solutionsIndexIntro'      => 'tsis_ui_solutions_index_intro',
	);

	$out = array();
	foreach ( $map as $output_key => $option_key ) {
		$out[ $output_key ] = tsis_bridge_str( get_option( $option_key, null ) );
	}
	return $out;
}

/**
 * §5.2 Navigation (menus WordPress + bouton principal).
 *
 * @return array
 */
function tsis_bridge_build_nav() {
	return array(
		'primary'    => tsis_bridge_menu( 'tsis_primary' ),
		'footer'     => tsis_bridge_menu( 'tsis_footer' ),
		'primaryCta' => tsis_bridge_cta( get_option( 'tsis_primary_cta_label', null ), get_option( 'tsis_primary_cta_href', null ) ),
	);
}

/**
 * Construit l'arbre d'un menu à partir de son emplacement.
 *
 * @param string $location Identifiant d'emplacement.
 * @return array
 */
function tsis_bridge_menu( $location ) {
	$locations = get_nav_menu_locations();
	if ( empty( $locations[ $location ] ) ) {
		return array(); // Menu non affecté → liste vide (pas d'invention).
	}

	$menu = wp_get_nav_menu_object( (int) $locations[ $location ] );
	if ( ! $menu ) {
		return array();
	}

	$items = wp_get_nav_menu_items( $menu->term_id );
	if ( ! is_array( $items ) || empty( $items ) ) {
		return array();
	}

	$by_id    = array();
	$children = array();
	foreach ( $items as $item ) {
		$by_id[ (int) $item->ID ]        = $item;
		$parent                          = (int) $item->menu_item_parent;
		$children[ $parent ][]           = (int) $item->ID;
	}

	$build = function ( $parent_id ) use ( &$build, $by_id, $children ) {
		$branch = array();
		if ( empty( $children[ $parent_id ] ) ) {
			return $branch;
		}
		foreach ( $children[ $parent_id ] as $id ) {
			$item     = $by_id[ $id ];
			$branch[] = array(
				'label'    => tsis_bridge_str( $item->title ),
				'href'     => tsis_bridge_href( $item->url ),
				'children' => $build( $id ),
			);
		}
		return $branch;
	};

	return $build( 0 );
}

/**
 * §5.3 Page d'accueil.
 *
 * @param WP_Post|null $page Page de slug « accueil ».
 * @return array|null
 */
function tsis_bridge_build_home( $page ) {
	if ( ! $page ) {
		return null;
	}
	$ctx = tsis_bridge_ctx_post( $page->ID );

	return array(
		'hero'        => array(
			'eyebrow'      => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_hero_eyebrow' ) ),
			'title'        => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_hero_title' ) ),
			'text'         => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_hero_text' ) ),
			'chips'        => tsis_bridge_text_list( $ctx, 'tsis_hero_chips' ),
			'image'        => tsis_bridge_image( tsis_bridge_raw( $ctx, 'tsis_hero_image' ) ),
			'imageAlt'     => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_hero_image_alt' ) ),
			'primaryCta'   => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_hero_primary_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_hero_primary_cta_href' ) ),
			'secondaryCta' => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_hero_secondary_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_hero_secondary_cta_href' ) ),
		),
		'about'       => array(
			'eyebrow'    => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_about_eyebrow' ) ),
			'title'      => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_about_title' ) ),
			'text'       => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_about_text' ) ),
			'note'       => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_about_note' ) ),
			'highlights' => tsis_bridge_text_list( $ctx, 'tsis_home_about_highlights' ),
			'image'      => tsis_bridge_image( tsis_bridge_raw( $ctx, 'tsis_home_about_image' ) ),
			'imageAlt'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_about_image_alt' ) ),
			'cta'        => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_home_about_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_home_about_cta_href' ) ),
		),
		'solutions'   => array(
			'eyebrow'  => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_solutions_eyebrow' ) ),
			'title'    => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_solutions_title' ) ),
			'intro'    => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_solutions_intro' ) ),
			'featured' => tsis_bridge_slug_list( $ctx, 'tsis_home_solutions_featured' ),
			'cta'      => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_home_solutions_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_home_solutions_cta_href' ) ),
		),
		'services'    => array(
			'eyebrow' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_services_eyebrow' ) ),
			'title'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_services_title' ) ),
			'intro'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_services_intro' ) ),
			'cta'     => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_home_services_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_home_services_cta_href' ) ),
		),
		'why'         => array(
			'eyebrow' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_why_eyebrow' ) ),
			'title'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_why_title' ) ),
			'intro'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_why_intro' ) ),
			'items'   => tsis_bridge_repeater( $ctx, 'tsis_home_why_items', array( 'title' => 'str', 'text' => 'str' ) ),
			'cta'     => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_home_why_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_home_why_cta_href' ) ),
		),
		'process'     => array(
			'eyebrow' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_process_eyebrow' ) ),
			'title'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_process_title' ) ),
			'intro'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_process_intro' ) ),
			'steps'   => tsis_bridge_repeater( $ctx, 'tsis_home_process_steps', array( 'num' => 'str', 'title' => 'str', 'text' => 'str' ) ),
		),
		'finalCta'    => array(
			'eyebrow' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_final_eyebrow' ) ),
			'title'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_final_title' ) ),
			'text'    => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_final_text' ) ),
			'cta'     => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_home_final_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_home_final_cta_href' ) ),
		),
	);
}

/**
 * §5.4 Page À propos.
 *
 * @param WP_Post|null $page Page de slug « a-propos ».
 * @return array|null
 */
function tsis_bridge_build_about( $page ) {
	if ( ! $page ) {
		return null;
	}
	$ctx = tsis_bridge_ctx_post( $page->ID );

	return array(
		'header'      => array(
			'eyebrow' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_about_eyebrow' ) ),
			'title'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_about_title' ) ),
			'intro'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_about_intro' ) ),
			'badge'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_about_badge' ) ),
		),
		'pillars'     => tsis_bridge_repeater( $ctx, 'tsis_about_pillars', array( 'title' => 'str', 'text' => 'str' ) ),
		'expertise'   => array(
			'eyebrow'    => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_about_expertise_eyebrow' ) ),
			'title'      => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_about_expertise_title' ) ),
			'paragraphs' => tsis_bridge_text_list( $ctx, 'tsis_about_expertise_paragraphs' ),
			'countries'  => tsis_bridge_text_list( $ctx, 'tsis_about_expertise_countries' ),
		),
		'stats'       => tsis_bridge_repeater( $ctx, 'tsis_about_stats', array( 'value' => 'str', 'label' => 'str' ) ),
		'teamSection' => array(
			'eyebrow' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_about_team_eyebrow' ) ),
			'title'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_about_team_title' ) ),
			'intro'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_about_team_intro' ) ),
		),
	);
}

/**
 * En-tête d'une page d'index (nos-services, nos-solutions, contact).
 * Priorité à la métadonnée de page, repli sur l'option tsis_ui_* (§5.9).
 *
 * @param string $slug        Slug de la page.
 * @param string $option_base Préfixe d'options (ex. tsis_ui_services_index).
 * @return array|null
 */
function tsis_bridge_build_index( $slug, $option_base ) {
	$page = tsis_bridge_page( $slug );
	if ( ! $page ) {
		return null;
	}
	$ctx = tsis_bridge_ctx_post( $page->ID );

	$read = function ( $suffix ) use ( $ctx, $option_base ) {
		$value = tsis_bridge_str( tsis_bridge_raw( $ctx, $option_base . $suffix ) );
		if ( null === $value ) {
			$value = tsis_bridge_str( get_option( $option_base . $suffix, null ) );
		}
		return $value;
	};

	return array(
		'eyebrow' => $read( '_eyebrow' ),
		'title'   => $read( '_title' ),
		'intro'   => $read( '_intro' ),
	);
}

/**
 * §5.5 Un service.
 *
 * @param WP_Post $post Publication du CPT service.
 * @return array
 */
function tsis_bridge_service_item( $post ) {
	$ctx = tsis_bridge_ctx_post( $post->ID );

	return array(
		'title'    => $post->post_title,
		'slug'     => $post->post_name,
		'excerpt'  => tsis_bridge_str( $post->post_excerpt ),
		'order'    => (int) $post->menu_order,
		'image'    => tsis_bridge_image( get_post_thumbnail_id( $post->ID ) ),
		'icon'     => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_service_icon' ) ),
		'body'     => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_service_body' ) ),
		'features' => tsis_bridge_repeater( $ctx, 'tsis_service_features', array( 'title' => 'str', 'text' => 'str' ) ),
		'seo'      => array(
			'title'       => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_service_seo_title' ) ),
			'description' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_service_seo_description' ) ),
		),
	);
}

/**
 * §5.6 Une réalisation.
 *
 * @param WP_Post $post Publication du CPT realisation.
 * @return array
 */
function tsis_bridge_realisation_item( $post ) {
	$ctx = tsis_bridge_ctx_post( $post->ID );

	return array(
		'title'    => $post->post_title,
		'slug'     => $post->post_name,
		'excerpt'  => tsis_bridge_str( $post->post_excerpt ),
		'order'    => (int) $post->menu_order,
		'secteurs' => tsis_bridge_term_slugs( $post->ID, 'secteur' ),
		'image'    => tsis_bridge_image( get_post_thumbnail_id( $post->ID ) ),
		'client'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_realisation_client' ) ),
		'year'     => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_realisation_year' ) ),
		'body'     => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_realisation_body' ) ),
		'results'  => tsis_bridge_repeater( $ctx, 'tsis_realisation_results', array( 'label' => 'str', 'value' => 'str' ) ),
		'gallery'  => tsis_bridge_gallery( $ctx, 'tsis_realisation_gallery' ),
		'seo'      => array(
			'title'       => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_realisation_seo_title' ) ),
			'description' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_realisation_seo_description' ) ),
		),
	);
}

/**
 * Liste des slugs de termes d'une taxonomie pour une publication.
 *
 * @param int    $post_id  Identifiant d'article.
 * @param string $taxonomy Nom de taxonomie.
 * @return array
 */
function tsis_bridge_term_slugs( $post_id, $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return array();
	}
	$slugs = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
	return ( is_wp_error( $slugs ) || ! is_array( $slugs ) ) ? array() : $slugs;
}

/**
 * Récupère les publications d'un type, triées par menu_order puis titre (§4).
 *
 * @param string $post_type Type de publication.
 * @return WP_Post[]
 */
function tsis_bridge_get_ordered_posts( $post_type ) {
	if ( ! post_type_exists( $post_type ) ) {
		return array();
	}
	$posts = get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'orderby'          => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'suppress_filters' => false,
		)
	);
	return is_array( $posts ) ? $posts : array();
}

/**
 * §5.5 Liste des services.
 *
 * @return array
 */
function tsis_bridge_build_services() {
	$out = array();
	foreach ( tsis_bridge_get_ordered_posts( 'service' ) as $post ) {
		$out[] = tsis_bridge_service_item( $post );
	}
	return $out;
}

/**
 * §5.6 Liste des réalisations.
 *
 * @return array
 */
function tsis_bridge_build_realisations() {
	$out = array();
	foreach ( tsis_bridge_get_ordered_posts( 'realisation' ) as $post ) {
		$out[] = tsis_bridge_realisation_item( $post );
	}
	return $out;
}

/**
 * §5.7 Taxonomie secteur.
 *
 * @return array
 */
function tsis_bridge_build_secteurs() {
	if ( ! taxonomy_exists( 'secteur' ) ) {
		return array();
	}
	$terms = get_terms(
		array(
			'taxonomy'   => 'secteur',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
	if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
		return array();
	}

	$out = array();
	foreach ( $terms as $term ) {
		$out[] = array(
			'slug'        => $term->slug,
			'name'        => $term->name,
			'count'       => (int) $term->count,
			'description' => tsis_bridge_str( term_description( $term->term_id, 'secteur' ) ),
		);
	}
	return $out;
}

/**
 * §5.8 Équipe (CPT tsis_member).
 *
 * @return array
 */
function tsis_bridge_build_team() {
	$out = array();
	foreach ( tsis_bridge_get_ordered_posts( 'tsis_member' ) as $post ) {
		$ctx = tsis_bridge_ctx_post( $post->ID );

		// Masqué si la métadonnée existe et vaut explicitement faux ; sinon visible (défaut vrai).
		$visible = tsis_bridge_raw( $ctx, 'tsis_member_visible' );
		if ( '' !== $visible && null !== $visible && ! tsis_bridge_bool( $visible ) ) {
			continue;
		}

		$out[] = array(
			'name'  => $post->post_title,
			'role'  => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_member_role' ) ),
			'photo' => tsis_bridge_image( tsis_bridge_raw( $ctx, 'tsis_member_photo' ) ),
			'order' => (int) $post->menu_order,
		);
	}
	return $out;
}

/**
 * Assemble le document complet (§4) et le met en cache dans un transient.
 *
 * @param string $lang Langue ('fr' ou 'en').
 * @return array
 */
function tsis_bridge_build_document( $lang ) {
	$cache_key = 'tsis_content_' . $lang;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$document = array(
		'schemaVersion' => 1,
		'generatedAt'   => gmdate( 'c' ),
		'lang'          => $lang,
		'source'        => 'wordpress',
		'site'          => tsis_bridge_build_site(),
		'contact'       => tsis_bridge_build_contact(),
		'social'        => tsis_bridge_build_social(),
		'nav'           => tsis_bridge_build_nav(),
		'ui'            => tsis_bridge_build_ui(),
		'pages'         => array(
			'home'           => tsis_bridge_build_home( tsis_bridge_page( 'accueil' ) ),
			'about'          => tsis_bridge_build_about( tsis_bridge_page( 'a-propos' ) ),
			'servicesIndex'  => tsis_bridge_build_index( 'nos-services', 'tsis_ui_services_index' ),
			'solutionsIndex' => tsis_bridge_build_index( 'nos-solutions', 'tsis_ui_solutions_index' ),
			'contact'        => tsis_bridge_build_index( 'contact', 'tsis_ui_contact_hero' ),
		),
		'services'      => tsis_bridge_build_services(),
		'realisations'  => tsis_bridge_build_realisations(),
		'secteurs'      => tsis_bridge_build_secteurs(),
		'team'          => tsis_bridge_build_team(),
	);

	set_transient( $cache_key, $document, TSIS_BRIDGE_CACHE_TTL );
	return $document;
}

/* -------------------------------------------------------------------------
 * 5. Branchements WordPress
 * ---------------------------------------------------------------------- */

add_action( 'init', 'tsis_bridge_register_post_type' );
add_action( 'init', 'tsis_bridge_register_meta' );
add_action( 'init', 'tsis_bridge_register_options' );
add_action( 'after_setup_theme', 'tsis_bridge_register_nav_menus', 20 );
add_action( 'rest_api_init', 'tsis_bridge_register_rest' );
add_action( 'acf/init', 'tsis_bridge_maybe_register_options_page' );

// Purge du cache du document à chaque modification éditoriale.
add_action( 'save_post', 'tsis_bridge_purge_cache' );
add_action( 'deleted_post', 'tsis_bridge_purge_cache' );
add_action( 'updated_option', 'tsis_bridge_purge_cache' );
add_action( 'added_option', 'tsis_bridge_purge_cache' );
add_action( 'deleted_option', 'tsis_bridge_purge_cache' );
add_action( 'edited_term', 'tsis_bridge_purge_cache' );
add_action( 'delete_term', 'tsis_bridge_purge_cache' );
add_action( 'acf/save_post', 'tsis_bridge_purge_cache', 20 );

// Fin du pont. Pour amorcer l'équipe depuis ce mu-plugin, appeler explicitement
// tsis_import_team() après avoir chargé wordpress/import/import-team.php.