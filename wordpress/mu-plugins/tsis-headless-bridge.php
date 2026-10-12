<?php
/**
 * Plugin Name: TSIS — Pont de contenu headless
 * Description: Adaptateur : expose le contenu éditorial EXISTANT de TSIS (réglages ACF, CPT service/realisation, taxonomie secteur, CPT tsis_member, menus) à l'API REST pour le site Astro, via la route GET /wp-json/tsis/v1/content.
 * Version: 2.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: TSIS
 * License: GPL-2.0-or-later
 * Text Domain: tsis-headless-bridge
 *
 * Greffon « must-use » autonome : aucune dépendance externe.
 *
 * ADAPTATEUR du modèle de contenu DÉJÀ rempli par les éditeurs (§5 bis du contrat) :
 * il LIT les champs ACF existants et les restitue au format du §4. Il ne crée aucun champ,
 * aucun contenu, aucun modèle parallèle. ACF est FACULTATIF pour la lecture : les valeurs sont
 * lues via get_option()/get_post_meta() (lues brutes), donc le pont fonctionne avec ACF comme
 * avec des métadonnées natives.
 *
 * Contrat de référence : docs/contrat-contenu-wp.md (§4 route, §5 modèle, §5 bis correspondance,
 * §6 livrables). Ce fichier n'écrit JAMAIS de contenu et ne coupe JAMAIS le site : une donnée
 * absente donne null ou [], jamais une valeur inventée ; toute erreur est capturée et rendue en
 * JSON lisible (sans trace PHP ni requête SQL). Aucun appel à exit()/die().
 *
 * @package TSIS\HeadlessBridge
 */

// Sécurité : chargé plusieurs fois (mu-plugins + plugins) → on ne définit les fonctions qu'une fois.
if ( defined( 'TSIS_BRIDGE_LOADED' ) ) {
	return;
}
define( 'TSIS_BRIDGE_LOADED', true );

// Pas d'accès direct au fichier : on sort proprement (jamais exit(), qui couperait le chargement).
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

define( 'TSIS_BRIDGE_VERSION', '2.0.0' );
define( 'TSIS_BRIDGE_CACHE_TTL', 300 ); // Secondes (aligné sur Cache-Control: max-age).

/** Slugs des pages du site (sert au repli des libellés d'interface et aux en-têtes d'index). */
function tsis_bridge_page_slugs() {
	return array( 'accueil', 'a-propos', 'nos-services', 'nos-solutions', 'contact' );
}

/* -------------------------------------------------------------------------
 * 1. Enregistrements : CPT, menus, métadonnées
 * ---------------------------------------------------------------------- */

/**
 * Enregistre le CPT `tsis_member` (§5.8).
 * Le CPT n'a pas de vue publique : il alimente uniquement le pont et l'administration.
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
 * Déclare les emplacements de menus (§5.2) : `tsis_primary` et `tsis_footer`.
 * On FUSIONNE avec les emplacements déjà déclarés (tsis_footer_navigation / tsis_footer_legal
 * du greffon tsis-footer, menu-1/menu-2 du thème) sans jamais les écraser.
 */
function tsis_bridge_register_nav_menus() {
	$existing  = function_exists( 'get_registered_nav_menus' ) ? get_registered_nav_menus() : array();
	$locations = array(
		'tsis_primary' => 'TSIS — Navigation principale',
		'tsis_footer'  => 'TSIS — Navigation de pied de page',
	);
	if ( is_array( $existing ) && ! empty( $existing ) ) {
		$locations = array_merge( $existing, $locations );
	}
	register_nav_menus( $locations );
}

/**
 * Enregistre une métadonnée en REST si elle ne l'est pas déjà (compatibilité éditeur).
 * On n'écrase jamais un enregistrement existant (ACF, etc.).
 *
 * @param string $post_type Type d'article.
 * @param string $name      Nom de la métadonnée.
 * @param array  $args      Arguments supplémentaires.
 */
function tsis_bridge_maybe_register_meta( $post_type, $name, $args = array() ) {
	if ( function_exists( 'registered_meta_key_exists' ) && registered_meta_key_exists( 'post', $name, $post_type ) ) {
		return;
	}
	$defaults = array(
		'single'        => true,
		'show_in_rest'  => true,
		'auth_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
	);
	register_post_meta( $post_type, $name, array_merge( $defaults, $args ) );
}

/**
 * Enregistre (de façon additive et prudente) les métadonnées scalaires lues par le pont.
 * Aucune métadonnée existante n'est renommée ni remplacée ; les répéteurs, galeries et
 * relations restent gérés par ACF (on évite tout schéma REST redondant).
 */
function tsis_bridge_register_meta() {
	// Champs de page (nouveaux champs éditoriaux du §5.3 / §5.4 / §5.9).
	$page_scalars = array(
		'tsis_hero_eyebrow', 'tsis_hero_title', 'tsis_hero_text', 'tsis_hero_image', 'tsis_hero_image_alt',
		'tsis_hero_primary_cta_label', 'tsis_hero_primary_cta_href',
		'tsis_hero_secondary_cta_label', 'tsis_hero_secondary_cta_href',
		'tsis_home_about_eyebrow', 'tsis_home_about_title', 'tsis_home_about_text', 'tsis_home_about_note',
		'tsis_home_about_image', 'tsis_home_about_image_alt', 'tsis_home_about_cta_label', 'tsis_home_about_cta_href',
		'tsis_home_solutions_eyebrow', 'tsis_home_solutions_title', 'tsis_home_solutions_intro',
		'tsis_home_solutions_cta_label', 'tsis_home_solutions_cta_href',
		'tsis_home_services_eyebrow', 'tsis_home_services_title', 'tsis_home_services_intro',
		'tsis_home_services_cta_label', 'tsis_home_services_cta_href',
		'tsis_home_why_eyebrow', 'tsis_home_why_title', 'tsis_home_why_intro',
		'tsis_home_why_cta_label', 'tsis_home_why_cta_href',
		'tsis_home_process_eyebrow', 'tsis_home_process_title', 'tsis_home_process_intro',
		'tsis_home_final_eyebrow', 'tsis_home_final_title', 'tsis_home_final_text',
		'tsis_home_final_cta_label', 'tsis_home_final_cta_href',
		'tsis_about_eyebrow', 'tsis_about_title', 'tsis_about_intro', 'tsis_about_badge',
		'tsis_about_expertise_eyebrow', 'tsis_about_expertise_title',
		'tsis_about_team_eyebrow', 'tsis_about_team_title', 'tsis_about_team_intro',
		'tsis_ui_project_eyebrow', 'tsis_ui_project_title',
		'tsis_ui_service_aside_title', 'tsis_ui_service_aside_text', 'tsis_ui_service_aside_cta_label',
		'tsis_ui_realisation_aside_title', 'tsis_ui_realisation_aside_text', 'tsis_ui_realisation_aside_cta_label',
		'tsis_ui_related_services_title', 'tsis_ui_related_realisations_title', 'tsis_ui_discover_label',
		'tsis_ui_service_hero_eyebrow', 'tsis_ui_hero_cta_label',
		'tsis_ui_discover_service_label', 'tsis_ui_discover_solution_label',
		'tsis_ui_contact_hero_eyebrow', 'tsis_ui_contact_hero_title', 'tsis_ui_contact_hero_intro',
		'tsis_ui_services_index_eyebrow', 'tsis_ui_services_index_title', 'tsis_ui_services_index_intro',
		'tsis_ui_solutions_index_eyebrow', 'tsis_ui_solutions_index_title', 'tsis_ui_solutions_index_intro',
	);
	foreach ( $page_scalars as $name ) {
		tsis_bridge_maybe_register_meta( 'page', $name );
	}

	// CPT service (champs existants, §5 bis).
	tsis_bridge_maybe_register_meta( 'service', 'service_presentation' );
	tsis_bridge_maybe_register_meta( 'service', 'service_ordre', array( 'type' => 'number' ) );

	// CPT realisation (champs existants, §5 bis).
	tsis_bridge_maybe_register_meta( 'realisation', 'projet_presentation' );
	tsis_bridge_maybe_register_meta( 'realisation', 'projet_demo_url' );
	tsis_bridge_maybe_register_meta( 'realisation', 'projet_temoignage_citation' );
	tsis_bridge_maybe_register_meta( 'realisation', 'projet_temoignage_nom' );
	tsis_bridge_maybe_register_meta( 'realisation', 'projet_temoignage_fonction' );
	tsis_bridge_maybe_register_meta( 'realisation', 'projet_accueil', array( 'type' => 'boolean' ) );

	// CPT tsis_member (§5.8).
	tsis_bridge_maybe_register_meta( 'tsis_member', 'tsis_member_role' );
	tsis_bridge_maybe_register_meta( 'tsis_member', 'tsis_member_photo' );
	tsis_bridge_maybe_register_meta( 'tsis_member', 'tsis_member_visible', array( 'type' => 'boolean' ) );
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
 * Lit une option globale en tenant compte du stockage ACF de la page d'options.
 * ACF stocke les valeurs de la page d'options sous le préfixe `options_` ; on accepte aussi
 * l'option nue (métadonnée native saisie hors ACF).
 *
 * @param string $name Nom du champ.
 * @return mixed
 */
function tsis_bridge_option( $name ) {
	$value = get_option( 'options_' . $name, null );
	if ( null === $value || '' === $value || false === $value ) {
		$plain = get_option( $name, null );
		if ( null !== $plain && '' !== $plain && false !== $plain ) {
			$value = $plain;
		}
	}
	return $value;
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
	return tsis_bridge_option( $key );
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
 * Échappe une valeur destinée à un attribut HTML.
 *
 * @param string $value Valeur.
 * @return string
 */
function tsis_bridge_attr( $value ) {
	if ( function_exists( 'esc_attr' ) ) {
		return esc_attr( $value );
	}
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
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
 * Normalise une spécification de sous-champ.
 * Accepte soit une chaîne (type 'str', même nom en sortie), soit un tableau
 * { 'name' => nom_source, 'type' => type }.
 *
 * @param string $output_key Clé de sortie.
 * @param mixed  $spec       Spécification.
 * @return array {name, type}
 */
function tsis_bridge_field_spec( $output_key, $spec ) {
	if ( is_array( $spec ) ) {
		return array(
			'name' => isset( $spec['name'] ) ? $spec['name'] : $output_key,
			'type' => isset( $spec['type'] ) ? $spec['type'] : 'str',
		);
	}
	return array(
		'name' => $output_key,
		'type' => (string) $spec,
	);
}

/**
 * Aplatit un répéteur ACF (§6.1) ou une métadonnée native, en liste JSON propre.
 * Convention ACF : la valeur de base est le nombre de lignes, les sous-champs sont
 * stockés sous `base_<i>_<souschamp>`.
 *
 * @param array  $ctx    Contexte de lecture.
 * @param string $base   Nom du champ de base.
 * @param array  $fields Map clé_de_sortie => spécification de sous-champ.
 * @return array Liste (vide si rien).
 */
function tsis_bridge_repeater( $ctx, $base, $fields ) {
	$raw = tsis_bridge_raw( $ctx, $base );

	// Cas 1 : tableau natif déjà structuré.
	if ( is_array( $raw ) ) {
		$rows = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out   = array();
			$empty = true;
			foreach ( $fields as $okey => $spec ) {
				$field = tsis_bridge_field_spec( $okey, $spec );
				$value = tsis_bridge_cast( isset( $row[ $field['name'] ] ) ? $row[ $field['name'] ] : null, $field['type'] );
				$out[ $okey ] = $value;
				if ( null !== $value && '' !== $value && false !== $value ) {
					$empty = false;
				}
			}
			if ( ! $empty ) {
				$rows[] = $out;
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
		$out   = array();
		$empty = true;
		foreach ( $fields as $okey => $spec ) {
			$field        = tsis_bridge_field_spec( $okey, $spec );
			$cell         = tsis_bridge_raw( $ctx, $base . '_' . $i . '_' . $field['name'] );
			$value        = tsis_bridge_cast( $cell, $field['type'] );
			$out[ $okey ] = $value;
			if ( null !== $value && '' !== $value && false !== $value ) {
				$empty = false;
			}
		}
		if ( ! $empty ) {
			$rows[] = $out;
		}
	}
	return $rows;
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

	// Convention ACF : nombre de lignes. Sous-champ attendu « item » ; repli sur le premier sous-champ non vide.
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
		// Options : on balaie les option_name correspondant au préfixe (avec ou sans `options_`).
		global $wpdb;
		$all    = array();
		$prefix = 'options_' . $prefix;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$all[ $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
				}
			}
		}
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
 * Résout une relation (ACF relationship ou native) en liste de slugs.
 *
 * @param array  $ctx Contexte de lecture.
 * @param string $key Nom du champ.
 * @return array
 */
function tsis_bridge_slug_list( $ctx, $key ) {
	$raw = tsis_bridge_raw( $ctx, $key );
	$out = array();
	if ( null === $raw || false === $raw ) {
		return $out;
	}

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
	} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
		$maybe = maybe_unserialize( $raw );
		if ( is_array( $maybe ) ) {
			foreach ( $maybe as $item ) {
				$append( $item );
			}
		} else {
			foreach ( explode( ',', $raw ) as $item ) {
				$append( trim( $item ) );
			}
		}
	}
	return $out;
}

/**
 * Aplatit une galerie ACF en liste d'objets image.
 * Une valeur vide (false, '', tableau vide) donne toujours [] (§5 bis).
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

/**
 * Cherche la première métadonnée non vide portant ce nom parmi les pages du site.
 * Sert de repli pour les libellés d'interface (§5.9) lorsque ACF les stocke sur les pages.
 *
 * @param string $name Nom de la métadonnée.
 * @return mixed
 */
function tsis_bridge_page_meta_any( $name ) {
	foreach ( tsis_bridge_page_slugs() as $slug ) {
		$page = tsis_bridge_page( $slug );
		if ( $page ) {
			$value = get_post_meta( $page->ID, $name, true );
			if ( null !== tsis_bridge_str( $value ) ) {
				return $value;
			}
		}
	}
	return null;
}

/* -------------------------------------------------------------------------
 * 3 bis. Nettoyage HTML (liste blanche, §6.1)
 * ---------------------------------------------------------------------- */

/**
 * Balises autorisées dans le corps nettoyé.
 *
 * @return array
 */
function tsis_bridge_allowed_tags() {
	return array( 'p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'em', 'br', 'a' );
}

/**
 * Retire les balises <script>, <style>, <iframe> et leur contenu (repli sans DOMDocument).
 *
 * @param string $html HTML brut.
 * @return string
 */
function tsis_bridge_strip_forbidden_html( $html ) {
	$html = preg_replace( '#<(script|style|iframe)\b[^>]*>.*?</\1>#is', '', $html );
	$html = preg_replace( '#<(script|style|iframe)\b[^>]*/?>#is', '', $html );
	return (string) $html;
}

/**
 * Valide et normalise un lien : chemin relatif pour l'interne, absolu pour l'externe.
 * Refuse les schémas dangereux (javascript:, data:).
 *
 * @param string $value Lien brut.
 * @return string|null
 */
function tsis_bridge_safe_href( $value ) {
	$href = tsis_bridge_href( $value );
	if ( null === $href ) {
		return null;
	}
	if ( 0 === stripos( $href, 'javascript:' ) || 0 === stripos( $href, 'data:' ) ) {
		return null;
	}
	return $href;
}

/**
 * Reconstruit le HTML des enfants d'un nœud selon la liste blanche.
 * - les balises non autorisées (div, span, section… Elementor) sont « dépliées » : leur texte est conservé ;
 * - aucune classe/style ni attribut d'événement n'est repris ; seul `href` survit sur <a> ;
 * - <script>, <style> et <iframe> sont supprimés avec leur contenu.
 *
 * @param DOMNode $node    Nœud parent.
 * @param array   $allowed Balises autorisées.
 * @return string
 */
function tsis_bridge_clean_children( $node, $allowed ) {
	$out = '';
	foreach ( $node->childNodes as $child ) {
		// Texte : on conserve la valeur, espaces normalisés.
		if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
			$text = preg_replace( '/\s+/u', ' ', (string) $child->nodeValue );
			if ( '' !== trim( $text ) ) {
				$out .= htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' );
			}
			continue;
		}
		if ( XML_ELEMENT_NODE !== $child->nodeType ) {
			continue;
		}
		$tag = strtolower( $child->nodeName );

		// Balises dangereuses : supprimées avec leur contenu.
		if ( in_array( $tag, array( 'script', 'style', 'iframe' ), true ) ) {
			continue;
		}

		if ( 'a' === $tag ) {
			$href = tsis_bridge_safe_href( $child->getAttribute( 'href' ) );
			if ( null === $href ) {
				// Lien invalide : on conserve le texte sans la balise.
				$out .= tsis_bridge_clean_children( $child, $allowed );
				continue;
			}
			$out .= '<a href="' . tsis_bridge_attr( $href ) . '">' . tsis_bridge_clean_children( $child, $allowed ) . '</a>';
			continue;
		}

		if ( in_array( $tag, $allowed, true ) ) {
			if ( 'br' === $tag ) {
				$out .= '<br />';
				continue;
			}
			$out .= '<' . $tag . '>' . tsis_bridge_clean_children( $child, $allowed ) . '</' . $tag . '>';
			continue;
		}

		// Balise non autorisée (wrapper Elementor, div, span…) : on conserve uniquement le texte.
		$out .= tsis_bridge_clean_children( $child, $allowed );
	}
	return $out;
}

/**
 * Nettoie un corps HTML selon la liste blanche (§6.1).
 * Retourne null si le résultat est vide.
 *
 * @param mixed $html HTML brut (texte simple accepté).
 * @return string|null
 */
function tsis_bridge_clean_html( $html ) {
	$html = tsis_bridge_str( $html );
	if ( null === $html ) {
		return null;
	}

	// Texte simple (aucune balise) : rien à nettoyer.
	if ( false === strpos( $html, '<' ) ) {
		return $html;
	}

	// Repli sans extension DOM : suppression des balises interdites puis des autres balises.
	if ( ! class_exists( 'DOMDocument' ) ) {
		$text = tsis_bridge_strip_forbidden_html( $html );
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text ) : strip_tags( $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return ( '' === $text ) ? null : $text;
	}

	$allowed    = tsis_bridge_allowed_tags();
	$doc        = new DOMDocument( '1.0', 'UTF-8' );
	$prev_state = libxml_use_internal_errors( true );
	$loaded     = $doc->loadHTML(
		'<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
		LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
	);
	libxml_clear_errors();
	libxml_use_internal_errors( $prev_state );

	if ( ! $loaded ) {
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( tsis_bridge_strip_forbidden_html( $html ) ) : strip_tags( $html );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return ( '' === $text ) ? null : $text;
	}

	$body = $doc->getElementsByTagName( 'body' )->item( 0 );
	if ( ! $body ) {
		return null;
	}

	$out = trim( tsis_bridge_clean_children( $body, $allowed ) );
	return ( '' === $out ) ? null : $out;
}

/* -------------------------------------------------------------------------
 * 4. Construction des blocs du document (§4 / §5 bis)
 * ---------------------------------------------------------------------- */

/**
 * §5.1 / §5 bis Identité du site.
 *
 * @return array
 */
function tsis_bridge_build_site() {
	$name = tsis_bridge_str( tsis_bridge_option( 'tsis_nom_affiche' ) );
	if ( null === $name ) {
		$name = tsis_bridge_str( get_option( 'blogname', null ) ); // Repli : réglage général WordPress.
	}

	$legal = tsis_bridge_str( tsis_bridge_option( 'tsis_raison_sociale' ) );
	if ( null === $legal ) {
		$legal = $name;
	}

	$logo_id = function_exists( 'get_theme_mod' ) ? (int) get_theme_mod( 'custom_logo' ) : 0;

	return array(
		'name'        => $name,
		'shortName'   => tsis_bridge_str( tsis_bridge_option( 'tsis_sigle' ) ),
		'legalName'   => $legal,
		'tagline'     => tsis_bridge_str( get_option( 'blogdescription', null ) ),
		'description' => tsis_bridge_str( tsis_bridge_option( 'tsis_presentation_courte' ) ),
		'logo'        => $logo_id ? tsis_bridge_image_from_id( $logo_id ) : null,
		'url'         => tsis_bridge_str( home_url() ),
	);
}

/**
 * Normalise un numéro de téléphone pour un lien `tel:` (sans espaces).
 *
 * @param string $value Numéro brut.
 * @return string
 */
function tsis_bridge_tel( $value ) {
	return (string) preg_replace( '/\s+/u', '', (string) $value );
}

/**
 * §5.1 / §5 bis Contact.
 * Téléphones : [tsis_telephone, tsis_telephone_secondaire] ;
 * label = valeur, href = tel: sans espaces, whatsapp = vrai pour le premier si tsis_whatsapp renseigné.
 *
 * @return array
 */
function tsis_bridge_build_contact() {
	$has_whatsapp = ( null !== tsis_bridge_str( tsis_bridge_option( 'tsis_whatsapp' ) ) );

	$phones = array();
	$values = array(
		tsis_bridge_option( 'tsis_telephone' ),
		tsis_bridge_option( 'tsis_telephone_secondaire' ),
	);
	$first = true;
	foreach ( $values as $raw ) {
		$value = tsis_bridge_str( $raw );
		if ( null === $value ) {
			continue;
		}
		$phones[] = array(
			'label'    => $value,
			'href'     => 'tel:' . tsis_bridge_tel( $value ),
			'whatsapp' => ( $first && $has_whatsapp ),
		);
		$first = false;
	}

	return array(
		'email'    => tsis_bridge_str( tsis_bridge_option( 'tsis_email' ) ),
		'address'  => tsis_bridge_str( tsis_bridge_option( 'tsis_adresse' ) ),
		'mapsUrl'  => tsis_bridge_href( tsis_bridge_option( 'tsis_google_maps' ) ),
		'whatsapp' => tsis_bridge_href( tsis_bridge_option( 'tsis_whatsapp' ) ),
		'phones'   => $phones,
	);
}

/**
 * §5.1 / §5 bis Réseaux sociaux.
 * Ordre imposé : Facebook, LinkedIn, Instagram, YouTube, TikTok ; seules les valeurs non vides
 * sont listées. Aucun libellé ni URL n'est inventé.
 *
 * @return array
 */
function tsis_bridge_build_social() {
	$networks = array(
		array( 'label' => 'Facebook',  'key' => 'tsis_facebook' ),
		array( 'label' => 'LinkedIn',  'key' => 'tsis_linkedin' ),
		array( 'label' => 'Instagram', 'key' => 'tsis_instagram' ),
		array( 'label' => 'YouTube',   'key' => 'tsis_youtube' ),
		array( 'label' => 'TikTok',    'key' => 'tsis_tiktok' ),
	);

	$out = array();
	foreach ( $networks as $network ) {
		$href = tsis_bridge_href( tsis_bridge_option( $network['key'] ) );
		if ( null === $href ) {
			continue; // Valeur vide : omise (jamais de libellé ni d'URL inventés).
		}
		$out[] = array(
			'label' => $network['label'],
			'href'  => $href,
		);
	}
	return $out;
}

/**
 * §5.9 Libellés d'interface (`ui`).
 * Lus en option (préfixe `options_`) puis, à défaut, dans les métadonnées des pages.
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
		$value = tsis_bridge_str( tsis_bridge_option( $option_key ) );
		if ( null === $value ) {
			$value = tsis_bridge_str( tsis_bridge_page_meta_any( $option_key ) );
		}
		$out[ $output_key ] = $value;
	}
	return $out;
}

/**
 * §5.2 / §5 bis Navigation.
 * primary : emplacement `tsis_primary` si affecté, sinon le menu nommé `main` ;
 * footer : emplacement `tsis_footer` si affecté, sinon null ;
 * footerServices : null (le site a un repli) ; primaryCta : tsis_cta_libelle / tsis_cta_url.
 *
 * @return array
 */
function tsis_bridge_build_nav() {
	$primary = tsis_bridge_menu_location( 'tsis_primary' );
	if ( empty( $primary ) ) {
		$primary = tsis_bridge_menu_named( 'main' ); // Repli sur le menu existant « main ».
	}

	$footer = tsis_bridge_menu_location( 'tsis_footer' );
	if ( empty( $footer ) ) {
		$footer = null;
	}

	return array(
		'primary'        => $primary,
		'footer'         => $footer,
		'footerServices' => null,
		'primaryCta'     => tsis_bridge_cta( tsis_bridge_option( 'tsis_cta_libelle' ), tsis_bridge_option( 'tsis_cta_url' ) ),
	);
}

/**
 * Construit l'arbre d'un menu à partir d'un emplacement.
 *
 * @param string $location Identifiant d'emplacement.
 * @return array
 */
function tsis_bridge_menu_location( $location ) {
	$locations = get_nav_menu_locations();
	if ( empty( $locations[ $location ] ) ) {
		return array(); // Menu non affecté → liste vide (pas d'invention).
	}
	return tsis_bridge_build_menu_tree( wp_get_nav_menu_items( (int) $locations[ $location ] ) );
}

/**
 * Construit l'arbre d'un menu à partir de son nom (ex. « main »).
 *
 * @param string $name Nom du menu.
 * @return array
 */
function tsis_bridge_menu_named( $name ) {
	$menu = wp_get_nav_menu_object( $name );
	if ( ! $menu || is_wp_error( $menu ) ) {
		return array();
	}
	return tsis_bridge_build_menu_tree( wp_get_nav_menu_items( $menu->term_id ) );
}

/**
 * Construit l'arbre {label, href, children[]} à partir des éléments d'un menu.
 *
 * @param array $items Éléments de menu (WP_Post).
 * @return array
 */
function tsis_bridge_build_menu_tree( $items ) {
	if ( ! is_array( $items ) || empty( $items ) ) {
		return array();
	}

	$by_id    = array();
	$children = array();
	foreach ( $items as $item ) {
		$by_id[ (int) $item->ID ] = $item;
		$parent                   = (int) $item->menu_item_parent;
		$children[ $parent ][]    = (int) $item->ID;
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
 * §5.3 Page d'accueil (blocs hero…process lus sur la page `accueil`, CTA final en options).
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
		'hero'      => array(
			'eyebrow'      => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_hero_eyebrow' ) ),
			'title'        => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_hero_title' ) ),
			'text'         => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_hero_text' ) ),
			'chips'        => tsis_bridge_text_list( $ctx, 'tsis_hero_chips' ),
			'image'        => tsis_bridge_image( tsis_bridge_raw( $ctx, 'tsis_hero_image' ) ),
			'imageAlt'     => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_hero_image_alt' ) ),
			'primaryCta'   => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_hero_primary_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_hero_primary_cta_href' ) ),
			'secondaryCta' => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_hero_secondary_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_hero_secondary_cta_href' ) ),
		),
		'about'     => array(
			'eyebrow'    => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_about_eyebrow' ) ),
			'title'      => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_about_title' ) ),
			'text'       => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_about_text' ) ),
			'note'       => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_about_note' ) ),
			'highlights' => tsis_bridge_text_list( $ctx, 'tsis_home_about_highlights' ),
			'image'      => tsis_bridge_image( tsis_bridge_raw( $ctx, 'tsis_home_about_image' ) ),
			'imageAlt'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_about_image_alt' ) ),
			'cta'        => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_home_about_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_home_about_cta_href' ) ),
		),
		'solutions' => array(
			'eyebrow'  => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_solutions_eyebrow' ) ),
			'title'    => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_solutions_title' ) ),
			'intro'    => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_solutions_intro' ) ),
			'featured' => tsis_bridge_slug_list( $ctx, 'tsis_home_solutions_featured' ),
			'cta'      => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_home_solutions_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_home_solutions_cta_href' ) ),
		),
		'services'  => array(
			'eyebrow' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_services_eyebrow' ) ),
			'title'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_services_title' ) ),
			'intro'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_services_intro' ) ),
			'cta'     => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_home_services_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_home_services_cta_href' ) ),
		),
		'why'       => array(
			'eyebrow' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_why_eyebrow' ) ),
			'title'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_why_title' ) ),
			'intro'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_why_intro' ) ),
			'items'   => tsis_bridge_repeater( $ctx, 'tsis_home_why_items', array( 'title' => 'str', 'text' => 'str' ) ),
			'cta'     => tsis_bridge_cta( tsis_bridge_raw( $ctx, 'tsis_home_why_cta_label' ), tsis_bridge_raw( $ctx, 'tsis_home_why_cta_href' ) ),
		),
		'process'   => array(
			'eyebrow' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_process_eyebrow' ) ),
			'title'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_process_title' ) ),
			'intro'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_process_intro' ) ),
			'steps'   => tsis_bridge_repeater( $ctx, 'tsis_home_process_steps', array( 'num' => 'str', 'title' => 'str', 'text' => 'str' ) ),
		),
		'finalCta'  => array(
			'eyebrow' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_home_final_eyebrow' ) ),
			'title'   => tsis_bridge_str( tsis_bridge_option( 'tsis_cta_titre' ) ),
			'text'    => tsis_bridge_str( tsis_bridge_option( 'tsis_cta_texte' ) ),
			'cta'     => tsis_bridge_cta( tsis_bridge_option( 'tsis_cta_libelle' ), tsis_bridge_option( 'tsis_cta_url' ) ),
		),
	);
}

/**
 * §5 bis Chiffres clés (option `tsis_chiffres_cles`).
 * value = valeur suivi du suffixe séparé par une espace (« 12 ans ») ; label = libellé.
 *
 * @return array
 */
function tsis_bridge_build_stats() {
	$rows = tsis_bridge_repeater(
		tsis_bridge_ctx_option(),
		'tsis_chiffres_cles',
		array(
			'tsis_chiffre_valeur'  => 'str',
			'tsis_chiffre_suffixe' => 'str',
			'tsis_chiffre_libelle' => 'str',
		)
	);

	$out = array();
	foreach ( $rows as $row ) {
		$valeur  = isset( $row['tsis_chiffre_valeur'] ) ? $row['tsis_chiffre_valeur'] : null;
		$suffixe = isset( $row['tsis_chiffre_suffixe'] ) ? $row['tsis_chiffre_suffixe'] : null;
		$libelle = isset( $row['tsis_chiffre_libelle'] ) ? $row['tsis_chiffre_libelle'] : null;

		$value = trim( (string) $valeur . ' ' . (string) $suffixe );
		if ( '' === $value && null === $libelle ) {
			continue;
		}
		$out[] = array(
			'value' => ( '' === $value ) ? null : $value,
			'label' => $libelle,
		);
	}
	return $out;
}

/**
 * §5.4 Page À propos (blocs lus sur la page `a-propos`, chiffres clés en options).
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
		'stats'       => tsis_bridge_build_stats(),
		'teamSection' => array(
			'eyebrow' => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_about_team_eyebrow' ) ),
			'title'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_about_team_title' ) ),
			'intro'   => tsis_bridge_str( tsis_bridge_raw( $ctx, 'tsis_about_team_intro' ) ),
		),
	);
}

/**
 * En-tête d'une page d'index (nos-services, nos-solutions, contact).
 * Priorité à la métadonnée de page, repli sur l'option `tsis_ui_*` (§5.9).
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
			$value = tsis_bridge_str( tsis_bridge_option( $option_base . $suffix ) );
		}
		return $value;
	};

	return array(
		'eyebrow'         => $read( '_eyebrow' ),
		'title'           => $read( '_title' ),
		'intro'           => $read( '_intro' ),
		'metaTitle'       => '' !== trim( (string) $page->post_title ) ? $page->post_title : null,
		'metaDescription' => tsis_bridge_str( $page->post_excerpt ) ? tsis_bridge_str( $page->post_excerpt ) : $read( '_intro' ),
	);
}

/**
 * Ordre d'affichage d'un service (§5 bis) : service_ordre, sinon menu_order.
 *
 * @param WP_Post $post Publication du CPT service.
 * @param array   $ctx  Contexte de lecture.
 * @return int
 */
function tsis_bridge_service_order( $post, $ctx ) {
	$ordre = tsis_bridge_raw( $ctx, 'service_ordre' );
	if ( is_numeric( $ordre ) && '' !== trim( (string) $ordre ) ) {
		return (int) $ordre;
	}
	return (int) $post->menu_order;
}

/**
 * §5 bis Un service (CPT service, champs existants).
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
		'order'    => tsis_bridge_service_order( $post, $ctx ),
		'image'    => tsis_bridge_image( get_post_thumbnail_id( $post->ID ) ),
		'body'     => tsis_bridge_clean_html( tsis_bridge_raw( $ctx, 'service_presentation' ) ),
		'features' => tsis_bridge_repeater(
			$ctx,
			'service_points_forts',
			array(
				'title' => array( 'name' => 'service_point_titre', 'type' => 'str' ),
				'text'  => array( 'name' => 'service_point_description', 'type' => 'str' ),
			)
		),
	);
}

/**
 * §5 bis Témoignage d'une réalisation.
 *
 * @param array $ctx Contexte de lecture.
 * @return array|null {quote, name, role} ou null si tout est vide.
 */
function tsis_bridge_testimonial( $ctx ) {
	$quote = tsis_bridge_str( tsis_bridge_raw( $ctx, 'projet_temoignage_citation' ) );
	$name  = tsis_bridge_str( tsis_bridge_raw( $ctx, 'projet_temoignage_nom' ) );
	$role  = tsis_bridge_str( tsis_bridge_raw( $ctx, 'projet_temoignage_fonction' ) );
	if ( null === $quote && null === $name && null === $role ) {
		return null;
	}
	return array(
		'quote' => $quote,
		'name'  => $name,
		'role'  => $role,
	);
}

/**
 * §5 bis Une réalisation (CPT realisation, champs existants).
 *
 * @param WP_Post $post Publication du CPT realisation.
 * @return array
 */
function tsis_bridge_realisation_item( $post ) {
	$ctx = tsis_bridge_ctx_post( $post->ID );

	return array(
		'title'           => $post->post_title,
		'slug'            => $post->post_name,
		'excerpt'         => tsis_bridge_str( $post->post_excerpt ),
		'order'           => (int) $post->menu_order,
		'secteurs'        => tsis_bridge_term_slugs( $post->ID, 'secteur' ),
		'image'           => tsis_bridge_image( get_post_thumbnail_id( $post->ID ) ),
		'body'            => tsis_bridge_clean_html( tsis_bridge_raw( $ctx, 'projet_presentation' ) ),
		'features'        => tsis_bridge_repeater(
			$ctx,
			'projet_points',
			array(
				'title' => array( 'name' => 'projet_point_titre', 'type' => 'str' ),
				'text'  => array( 'name' => 'projet_point_description', 'type' => 'str' ),
			)
		),
		'gallery'         => tsis_bridge_gallery( $ctx, 'projet_galerie' ),
		'relatedServices' => tsis_bridge_slug_list( $ctx, 'projet_services' ),
		'featured'        => tsis_bridge_bool( tsis_bridge_raw( $ctx, 'projet_accueil' ) ),
		'demoUrl'         => tsis_bridge_href( tsis_bridge_raw( $ctx, 'projet_demo_url' ) ),
		'testimonial'     => tsis_bridge_testimonial( $ctx ),
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
 * §5 bis Liste des services, triée par `service_ordre` puis titre.
 *
 * @return array
 */
function tsis_bridge_build_services() {
	$out = array();
	foreach ( tsis_bridge_get_ordered_posts( 'service' ) as $post ) {
		$out[] = tsis_bridge_service_item( $post );
	}

	// Tri par ordre éditorial (service_ordre), puis titre : corrige l'ordre alphabétique actuel.
	usort(
		$out,
		function ( $a, $b ) {
			$oa = ( null === $a['order'] ) ? PHP_INT_MAX : (int) $a['order'];
			$ob = ( null === $b['order'] ) ? PHP_INT_MAX : (int) $b['order'];
			if ( $oa === $ob ) {
				return strcasecmp( (string) $a['title'], (string) $b['title'] );
			}
			return ( $oa < $ob ) ? -1 : 1;
		}
	);
	return $out;
}

/**
 * §5 bis Liste des réalisations.
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
add_action( 'init', 'tsis_bridge_register_meta', 20 );
add_action( 'after_setup_theme', 'tsis_bridge_register_nav_menus', 20 );
add_action( 'rest_api_init', 'tsis_bridge_register_rest' );

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