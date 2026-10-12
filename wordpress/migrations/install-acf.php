<?php
/**
 * Importe les groupes de champs ACF livrés avec ce lot.
 *
 * Usage : wp eval-file install-acf.php [chemin/vers/tsis-content-fields.json]
 *
 * Les groupes sont importés par `acf_import_field_group()` : ACF crée les nouveaux groupes et
 * met à jour ceux qui portent la même clé. Aucun groupe ni champ existant n'est supprimé.
 */

$chemin = $args[0] ?? ( __DIR__ . '/../acf/tsis-content-fields.json' );

if ( ! file_exists( $chemin ) ) {
	echo "Fichier introuvable : $chemin\n";
	return;
}
if ( ! function_exists( 'acf_import_field_group' ) ) {
	echo "ACF PRO est requis (acf_import_field_group absent).\n";
	return;
}

$groupes = json_decode( (string) file_get_contents( $chemin ), true );
if ( ! is_array( $groupes ) ) {
	echo "JSON illisible.\n";
	return;
}

$existants = array();
foreach ( (array) acf_get_field_groups() as $g ) {
	if ( ! isset( $existants[ $g['key'] ] ) ) {
		$existants[ $g['key'] ] = $g;
	}
}

$crees = 0;
$maj   = 0;
foreach ( $groupes as $groupe ) {
	if ( empty( $groupe['key'] ) || empty( $groupe['fields'] ) ) {
		continue;
	}
	$avant = 0;
	if ( isset( $existants[ $groupe['key'] ] ) ) {
		// ACF ne remplace pas un groupe par sa clé : il faut lui donner l'identifiant existant,
		// sinon chaque import crée un second groupe identique dans l'interface.
		$existant          = $existants[ $groupe['key'] ];
		$groupe['ID']      = $existant['ID'];
		$avant             = count( (array) acf_get_fields( $existant ) );
	}
	$res = acf_import_field_group( $groupe );
	if ( ! $res ) {
		echo 'ÉCHEC : ' . $groupe['key'] . "\n";
		continue;
	}
	if ( $avant ) {
		$maj++;
		echo sprintf( "  maj      %-38s %2d champ(s) direct(s)\n", $groupe['title'], count( (array) $groupe['fields'] ) );
	} else {
		$crees++;
		echo sprintf( "  créé     %-38s %2d champ(s) direct(s)\n", $groupe['title'], count( (array) $groupe['fields'] ) );
	}
}

echo "Groupes créés : $crees | mis à jour : $maj\n";
echo 'Total de groupes de champs en base : ' . count( (array) acf_get_field_groups() ) . "\n";
