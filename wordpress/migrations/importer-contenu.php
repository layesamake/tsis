<?php
/**
 * Migration du contenu extrait des données Elementor vers les champs ACF.
 *
 * Contexte : le WordPress de préproduction a été construit avec Elementor. Avant de retirer
 * le constructeur, le contenu éditorial (textes, liens, images, équipe) a été extrait puis
 * consigné dans `contenu-extrait-elementor.json`. Ce script le verse dans les champs ACF.
 *
 * Usage :
 *   wp eval-file wordpress/migrations/importer-contenu.php simulation   (n'écrit rien)
 *   wp eval-file wordpress/migrations/importer-contenu.php                (écrit)
 *
 * Garanties :
 *   - aucune valeur inventée : le script ne fait que recopier le fichier de migration ;
 *   - aucune valeur existante écrasée : un champ déjà renseigné est laissé tel quel ;
 *   - idempotent : une seconde exécution ne crée aucun doublon ;
 *   - retour arrière : les identifiants créés sont listés, les champs sont modifiables à la main.
 */

$simulation = in_array( 'simulation', array_map( 'strval', (array) $args ), true );
$racine     = __DIR__;
$fichiers   = array_values( array_filter( (array) glob( $racine . '/contenu-*.json' ) ) );

if ( ! $fichiers ) {
	echo "Aucun fichier de migration (contenu-*.json) dans $racine\n";
	return;
}
if ( ! function_exists( 'update_field' ) ) {
	echo "ACF n'est pas actif : migration impossible.\n";
	return;
}

/* Les fichiers sont lus dans l'ordre alphabétique ; chaque champ vide est renseigné une fois. */
$migration = array( 'champs' => array(), 'equipe' => array(), 'options_wp' => array() );
foreach ( $fichiers as $f ) {
	$part = json_decode( (string) file_get_contents( $f ), true );
	if ( ! is_array( $part ) ) {
		echo 'Fichier illisible, ignoré : ' . basename( $f ) . "\n";
		continue;
	}
	echo 'Source : ' . basename( $f ) . "\n";
	$migration['champs']     = array_merge( $migration['champs'], (array) ( $part['champs'] ?? array() ) );
	$migration['options_wp'] = array_merge( $migration['options_wp'], (array) ( $part['options_wp'] ?? array() ) );
	if ( ! empty( $part['equipe'] ) ) {
		$migration['equipe'] = (array) $part['equipe'];
	}
}
if ( ! $migration['champs'] && ! $migration['equipe'] ) {
	echo "Rien à migrer.\n";
	return;
}

$rapport = array( 'renseignes' => array(), 'deja_remplis' => array(), 'ignores' => array(), 'membres_crees' => array(), 'membres_existants' => array(), 'images' => array() );

/** Page cible pour une clé, ou « options ». */
$cible = static function ( $cle ) {
	$par_page = array(
		'tsis_hero_'  => 'accueil',
		'tsis_home_'  => 'accueil',
		'tsis_about_' => 'a-propos',
	);
	foreach ( $par_page as $prefixe => $slug ) {
		if ( 0 === strpos( $cle, $prefixe ) ) {
			return $slug;
		}
	}
	return 'options';
};

/** Identifiant ACF d'une page, ou « options ». */
$contexte = static function ( $cible ) {
	if ( 'options' === $cible ) {
		return 'options';
	}
	$page = get_page_by_path( $cible, OBJECT, 'page' );
	return $page ? $page->ID : null;
};

/** Résout un média par nom de fichier (déjà téléversé) et renvoie son identifiant. */
$media_par_fichier = static function ( $fichier, &$rapport ) {
	if ( ! $fichier ) {
		return 0;
	}
	global $wpdb;
	$nom = preg_replace( '/\.[a-z0-9]+$/i', '', $fichier );
	$id  = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND (guid LIKE %s OR post_name = %s) ORDER BY ID DESC LIMIT 1",
			'%/' . $wpdb->esc_like( $fichier ),
			$nom
		)
	);
	if ( $id ) {
		$rapport['images'][] = $fichier . ' → média #' . $id;
	}
	return $id;
};

/** Valeur d'un champ ACF, quel que soit le contexte. */
$lire = static function ( $nom, $post_id ) {
	$valeur = get_field( $nom, $post_id );
	if ( null === $valeur || '' === $valeur || array() === $valeur || false === $valeur ) {
		return null;
	}
	return $valeur;
};

/** Prépare une valeur pour le champ ACF visé (gère les répéteurs). */
$preparer = static function ( $nom, $valeur, $post_id, $media_par_fichier, &$rapport ) {
	$champ = function_exists( 'acf_get_field' ) ? acf_get_field( $nom ) : null;
	if ( ! $champ ) {
		return array( 'ok' => false, 'raison' => 'champ ACF inconnu' );
	}
	$acf_id = $champ['key'];

	if ( 'repeater' === $champ['type'] && is_array( $valeur ) ) {
		$sous      = array();
		foreach ( (array) $champ['sub_fields'] as $sf ) {
			$sous[] = $sf['name'];
		}
		$premier   = $sous[0] ?? 'item';
		$lignes    = array();
		foreach ( $valeur as $ligne ) {
			if ( is_array( $ligne ) ) {
				$propre = array();
				foreach ( $ligne as $k => $v ) {
					$propre[ in_array( $k, $sous, true ) ? $k : $premier ] = $v;
				}
				$lignes[] = $propre;
			} else {
				$lignes[] = array( $premier => $ligne );
			}
		}
		return array( 'ok' => true, 'acf_id' => $acf_id, 'valeur' => $lignes );
	}

	if ( 'relationship' === $champ['type'] && is_array( $valeur ) ) {
		$ids = array();
		foreach ( $valeur as $slug ) {
			$p = get_page_by_path( (string) $slug, OBJECT, $champ['post_type'] ?: 'realisation' );
			if ( $p ) {
				$ids[] = $p->ID;
			}
		}
		return array( 'ok' => true, 'acf_id' => $acf_id, 'valeur' => $ids );
	}

	if ( 'image' === $champ['type'] ) {
		$id = $media_par_fichier( (string) $valeur, $rapport );
		if ( ! $id ) {
			return array( 'ok' => false, 'raison' => 'média introuvable : ' . $valeur );
		}
		return array( 'ok' => true, 'acf_id' => $acf_id, 'valeur' => $id );
	}

	return array( 'ok' => true, 'acf_id' => $acf_id, 'valeur' => $valeur );
};

/* ------------------------------------------------------------------ *
 * 1) Champs scalaires et répéteurs
 * ------------------------------------------------------------------ */
foreach ( $migration['champs'] as $nom => $valeur ) {
	// Clés de repère : servent à résoudre un média, écrites par le bloc « équipe » ou ci-dessous.
	if ( str_ends_with( $nom, '_image_fichier' ) ) {
		$champ_image = substr( $nom, 0, -strlen( '_fichier' ) );
		$post_id     = $contexte( $cible( $champ_image ) );
		if ( null === $post_id ) {
			$rapport['ignores'][] = $nom . ' (page absente)';
			continue;
		}
		if ( null !== $lire( $champ_image, $post_id ) ) {
			$rapport['deja_remplis'][] = $champ_image;
			continue;
		}
		$prep = $preparer( $champ_image, $valeur, $post_id, $media_par_fichier, $rapport );
		if ( ! $prep['ok'] ) {
			$rapport['ignores'][] = $champ_image . ' (' . $prep['raison'] . ')';
			continue;
		}
		if ( ! $simulation ) {
			update_field( $prep['acf_id'], $prep['valeur'], $post_id );
		}
		$rapport['renseignes'][] = $champ_image;
		continue;
	}

	$post_id = $contexte( $cible( $nom ) );
	if ( null === $post_id ) {
		$rapport['ignores'][] = $nom . ' (page absente)';
		continue;
	}
	if ( null !== $lire( $nom, $post_id ) ) {
		$rapport['deja_remplis'][] = $nom;
		continue;
	}
	$prep = $preparer( $nom, $valeur, $post_id, $media_par_fichier, $rapport );
	if ( ! $prep['ok'] ) {
		$rapport['ignores'][] = $nom . ' (' . $prep['raison'] . ')';
		continue;
	}
	if ( ! $simulation ) {
		update_field( $prep['acf_id'], $prep['valeur'], $post_id );
	}
	$rapport['renseignes'][] = $nom;
}

/* ------------------------------------------------------------------ *
 * 2) Réglages natifs WordPress (titre secondaire du site, etc.)
 * ------------------------------------------------------------------ */
foreach ( (array) $migration['options_wp'] as $option => $valeur ) {
	$actuelle = get_option( $option );
	if ( ! empty( $actuelle ) ) {
		$rapport['deja_remplis'][] = 'option ' . $option;
		continue;
	}
	if ( ! $simulation ) {
		update_option( $option, $valeur );
	}
	$rapport['renseignes'][] = 'option ' . $option;
}

/* ------------------------------------------------------------------ *
 * 3) Équipe : un membre = une publication tsis_member
 * ------------------------------------------------------------------ */
foreach ( (array) $migration['equipe'] as $membre ) {
	$nom = trim( (string) ( $membre['nom'] ?? '' ) );
	if ( '' === $nom ) {
		continue;
	}
	$existant = null;
	$trouve   = get_posts(
		array(
			'post_type'              => 'tsis_member',
			'post_status'            => array( 'publish', 'draft', 'private' ),
			'title'                  => $nom,
			'numberposts'            => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);
	if ( $trouve ) {
		$existant = get_post( (int) $trouve[0] );
	}
	if ( $existant ) {
		$rapport['membres_existants'][] = $nom;
		$post_id = $existant->ID;
	} else {
		if ( $simulation ) {
			$rapport['membres_crees'][] = $nom . ' (simulation)';
			continue;
		}
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'tsis_member',
				'post_title'  => $nom,
				'post_status' => 'publish',
				'menu_order'  => (int) ( $membre['ordre'] ?? 0 ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			$rapport['ignores'][] = 'équipe : ' . $nom . ' (' . $post_id->get_error_message() . ')';
			continue;
		}
		$rapport['membres_crees'][] = $nom;
	}

	if ( null === $lire( 'tsis_member_role', $post_id ) ) {
		$champ = acf_get_field( 'tsis_member_role' );
		if ( $champ ) {
			update_field( $champ['key'], (string) ( $membre['fonction'] ?? '' ), $post_id );
		}
	}
	if ( null === $lire( 'tsis_member_photo', $post_id ) && ! empty( $membre['photo_fichier'] ) ) {
		$media = $media_par_fichier( $membre['photo_fichier'], $rapport );
		$champ = acf_get_field( 'tsis_member_photo' );
		if ( $media && $champ ) {
			update_field( $champ['key'], $media, $post_id );
		}
	}
	if ( ! $simulation ) {
		wp_update_post(
			array(
				'ID'         => $post_id,
				'menu_order' => (int) ( $membre['ordre'] ?? 0 ),
			)
		);
	}
}

/* ------------------------------------------------------------------ *
 * 3) Rapport
 * ------------------------------------------------------------------ */
echo $simulation ? "== SIMULATION (aucune écriture) ==\n" : "== MIGRATION APPLIQUÉE ==\n";
echo 'Champs renseignés  : ' . count( $rapport['renseignes'] ) . "\n";
echo 'Déjà remplis (inchangés) : ' . count( $rapport['deja_remplis'] ) . "\n";
if ( $rapport['deja_remplis'] ) {
	echo '   ' . implode( ', ', array_slice( $rapport['deja_remplis'], 0, 12 ) ) . "\n";
}
echo 'Ignorés : ' . count( $rapport['ignores'] ) . "\n";
foreach ( $rapport['ignores'] as $raison ) {
	echo '   - ' . $raison . "\n";
}
echo 'Membres créés : ' . count( $rapport['membres_crees'] ) . ' | déjà présents : ' . count( $rapport['membres_existants'] ) . "\n";
echo 'Médias résolus : ' . count( $rapport['images'] ) . "\n";
