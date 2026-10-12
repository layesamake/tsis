<?php
/**
 * TSIS — Amorçage de l'équipe (CPT tsis_member).
 *
 * Crée les 25 membres de l'équipe à partir de docs/site-source/api/team.json
 * (champs {name, role, img}) :
 *   - name → titre de la publication ;
 *   - role → métadonnée « tsis_member_role » ;
 *   - img  → photo : téléchargée dans la médiathèque si possible, sinon l'URL est
 *            enregistrée telle quelle dans la métadonnée « tsis_member_photo »
 *            (le pont sait résoudre une URL comme un identifiant de média).
 *   - l'ordre du fichier détermine « menu_order » (1..25).
 *
 * Idempotent : un membre déjà présent (même titre) n'est jamais dupliqué.
 * Aucune donnée n'est inventée : une entrée incomplète est signalée, jamais complétée.
 *
 * Exécution (WP-CLI) :
 *   wp eval-file wordpress/import/import-team.php
 *   wp eval-file wordpress/import/import-team.php /chemin/vers/team.json
 *
 * Le script ne s'exécute automatiquement qu'en contexte WP-CLI ; appelé depuis le
 * pont, il faut invoquer explicitement tsis_import_team().
 *
 * @package TSIS\HeadlessBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Pas d'accès direct.
}

/**
 * Résout le chemin du fichier team.json.
 *
 * Priorité : argument CLI → variable d'environnement TSIS_TEAM_JSON → chemins relatifs connus.
 *
 * @param array $args Arguments passés à `wp eval-file`.
 * @return string|null Chemin lisible, ou null.
 */
function tsis_import_team_json_path( $args = array() ) {
	$candidates = array();

	if ( isset( $args[0] ) && is_string( $args[0] ) && '' !== $args[0] ) {
		$candidates[] = $args[0];
	}
	$env = getenv( 'TSIS_TEAM_JSON' );
	if ( is_string( $env ) && '' !== $env ) {
		$candidates[] = $env;
	}
	// Chemins relatifs usuels (dépôt complet, dossier import seul, thème).
	$candidates[] = __DIR__ . '/../../docs/site-source/api/team.json';
	$candidates[] = __DIR__ . '/team.json';
	$candidates[] = __DIR__ . '/data/team.json';
	if ( function_exists( 'get_stylesheet_directory' ) ) {
		$candidates[] = get_stylesheet_directory() . '/tsis-team.json';
	}

	foreach ( $candidates as $path ) {
		if ( is_string( $path ) && '' !== $path && is_readable( $path ) ) {
			return $path;
		}
	}
	return null;
}

/**
 * Enregistre la photo d'un membre : téléchargement si possible, sinon URL en métadonnée.
 *
 * @param int    $post_id  Identifiant du membre.
 * @param string $img_url  URL de la photo issue de team.json.
 * @return string 'downloaded' | 'url' | 'skipped' | 'empty'.
 */
function tsis_import_team_photo( $post_id, $img_url ) {
	// Déjà renseignée → ne pas écraser (idempotence).
	if ( '' !== get_post_meta( $post_id, 'tsis_member_photo', true ) ) {
		return 'skipped';
	}
	if ( ! is_string( $img_url ) || '' === trim( $img_url ) ) {
		return 'empty';
	}
	$img_url = trim( $img_url );

	// Tentative de téléchargement dans la médiathèque.
	$attachment_id = 0;
	try {
		if ( ! function_exists( 'media_sideload_image' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( function_exists( 'media_sideload_image' ) ) {
			$result = media_sideload_image( $img_url, $post_id, get_the_title( $post_id ), 'id' );
			if ( ! is_wp_error( $result ) && is_numeric( $result ) ) {
				$attachment_id = (int) $result;
			}
		}
	} catch ( Throwable $e ) {
		$attachment_id = 0;
	}

	if ( $attachment_id > 0 ) {
		update_post_meta( $post_id, 'tsis_member_photo', $attachment_id );
		return 'downloaded';
	}

	// Repli : l'URL est conservée telle quelle ; le pont la résoudra en objet image.
	update_post_meta( $post_id, 'tsis_member_photo', $img_url );
	return 'url';
}

/**
 * Crée (ou complète) les membres à partir du tableau décodé de team.json.
 *
 * @param array  $members Tableau d'objets {name, role, img}.
 * @param string $source  Chemin du fichier source (journal).
 * @return array Statistiques.
 */
function tsis_import_team_from_data( $members, $source = '' ) {
	$stats = array(
		'created'    => 0,
		'existing'   => 0,
		'photo_dl'   => 0,
		'photo_url'  => 0,
		'skipped'    => 0,
		'errors'     => array(),
	);

	$position = 0;
	foreach ( $members as $index => $member ) {
		$position++;

		if ( ! is_array( $member ) ) {
			$stats['errors'][] = 'Entrée #' . $index . ' ignorée : format invalide.';
			continue;
		}

		$name = isset( $member['name'] ) ? trim( (string) $member['name'] ) : '';
		if ( '' === $name ) {
			$stats['errors'][] = 'Entrée #' . $index . ' ignorée : nom manquant.';
			continue;
		}

		$role = isset( $member['role'] ) ? trim( (string) $member['role'] ) : '';
		$img  = isset( $member['img'] ) ? trim( (string) $member['img'] ) : '';

		// Recherche d'un membre existant portant exactement ce titre (idempotence).
		$existing = get_posts(
			array(
				'post_type'      => 'tsis_member',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'title'          => $name,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( ! empty( $existing ) ) {
			$post_id = (int) $existing[0];
			$stats['existing']++;
		} else {
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'tsis_member',
					'post_title'  => $name,
					'post_status' => 'publish',
					'menu_order'  => $position,
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				$stats['errors'][] = 'Échec de création pour « ' . $name . ' » : ' . $post_id->get_error_message();
				continue;
			}
			$stats['created']++;
		}

		// Fonction (jamais écrasée si déjà saisie manuellement).
		if ( '' !== $role && '' === get_post_meta( $post_id, 'tsis_member_role', true ) ) {
			update_post_meta( $post_id, 'tsis_member_role', $role );
		}

		// Visibilité : valeur par défaut du contrat (visible), posée une seule fois.
		if ( '' === get_post_meta( $post_id, 'tsis_member_visible', true ) ) {
			update_post_meta( $post_id, 'tsis_member_visible', '1' );
		}

		// Photo.
		$photo = tsis_import_team_photo( $post_id, $img );
		if ( 'downloaded' === $photo ) {
			$stats['photo_dl']++;
		} elseif ( 'url' === $photo ) {
			$stats['photo_url']++;
		}
	}

	return $stats;
}

/**
 * Point d'entrée : lit team.json et amorce l'équipe.
 *
 * @param array $args Arguments CLI optionnels.
 * @return array Statistiques (ou erreur).
 */
function tsis_import_team( $args = array() ) {
	if ( ! post_type_exists( 'tsis_member' ) ) {
		// Le CPT est déclaré par le mu-plugin : on tente de le charger s'il est présent.
		$bridge = __DIR__ . '/../mu-plugins/tsis-headless-bridge.php';
		if ( is_readable( $bridge ) ) {
			require_once $bridge;
		}
	}
	if ( ! post_type_exists( 'tsis_member' ) ) {
		return array( 'error' => 'Le type de contenu tsis_member n’est pas enregistré. Installez d’abord le mu-plugin wordpress/mu-plugins/tsis-headless-bridge.php.' );
	}

	$path = tsis_import_team_json_path( $args );
	if ( null === $path ) {
		return array( 'error' => 'Fichier team.json introuvable. Indiquez son chemin : wp eval-file wordpress/import/import-team.php /chemin/team.json' );
	}

	$raw = file_get_contents( $path );
	if ( false === $raw ) {
		return array( 'error' => 'Lecture impossible du fichier : ' . $path );
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return array( 'error' => 'JSON invalide dans : ' . $path );
	}

	$stats           = tsis_import_team_from_data( $data, $path );
	$stats['source'] = $path;
	return $stats;
}

// Exécution automatique uniquement en contexte WP-CLI (jamais lors d'un chargement web).
if ( defined( 'WP_CLI' ) && WP_CLI && ! defined( 'TSIS_TEAM_IMPORT_RAN' ) ) {
	define( 'TSIS_TEAM_IMPORT_RAN', true );
	$tsis_args  = ( isset( $args ) && is_array( $args ) ) ? $args : array();
	$tsis_stats = tsis_import_team( $tsis_args );

	if ( isset( $tsis_stats['error'] ) ) {
		WP_CLI::error( $tsis_stats['error'] );
	} else {
		WP_CLI::log( 'Source : ' . $tsis_stats['source'] );
		WP_CLI::log( 'Créés : ' . $tsis_stats['created'] . ' · Déjà présents : ' . $tsis_stats['existing'] );
		WP_CLI::log( 'Photos téléchargées : ' . $tsis_stats['photo_dl'] . ' · Photos en URL de repli : ' . $tsis_stats['photo_url'] );
		if ( ! empty( $tsis_stats['errors'] ) ) {
			foreach ( $tsis_stats['errors'] as $message ) {
				WP_CLI::warning( $message );
			}
		}
		WP_CLI::success( 'Amorçage terminé : ' . ( $tsis_stats['created'] + $tsis_stats['existing'] ) . ' membre(s) traité(s).' );
	}
}