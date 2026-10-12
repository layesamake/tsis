/**
 * Configuration du site TSIS.
 *
 * Ce fichier n'est plus une source de vérité : il réexpose le contenu éditorial de la couche de
 * contenu (`src/content`), alimentée par WordPress (voir docs/contrat-contenu-wp.md §5.1 et §5.2).
 * Les composants continuent d'importer les mêmes noms : la source a changé, pas le code.
 */

export {
  CONTACT,
  NAV,
  NAV_FOOTER,
  NAV_FOOTER_SERVICES,
  PRIMARY_CTA,
  SITE,
  SOCIAL,
} from '../content';
