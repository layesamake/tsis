/**
 * Contenu de la page « À propos ».
 * À aligner sur les champs ACF WordPress lors du branchement headless.
 */

export const ABOUT_PAGE = {
  eyebrow: 'À propos de TSI',
  title: 'La technologie pensée pour les réalités africaines.',
  intro:
    'Depuis plus de 12 ans, Touba Solution Informatique conçoit des solutions numériques sur mesure pour les entreprises et institutions.',
  badge: "12+ ans d'expérience",
} as const;

export const PILLARS = [
  {
    title: 'Mission',
    text: 'Accompagner la transformation digitale avec des solutions utiles, performantes et sécurisées.',
  },
  {
    title: 'Vision',
    text: 'Faire du numérique un véritable levier de croissance en Afrique.',
  },
  {
    title: 'Valeurs',
    text: 'Expertise, confiance et engagement au service de chaque projet.',
  },
] as const;

export const EXPERTISE = {
  eyebrow: 'Une expertise ancrée en Afrique',
  title: 'Présents là où vos projets se développent.',
  paragraphs: [
    'Touba Solution Informatique (TSI) est une entreprise sénégalaise spécialisée dans le développement de solutions numériques sur mesure.',
    "Nous accompagnons les entreprises et institutions africaines dans leur transformation digitale en leur fournissant des outils modernes, performants et sécurisés.",
  ],
  /** Pays de présence (issus du site actuel). */
  countries: [
    'Sénégal',
    "Côte d'Ivoire",
    'Mali',
    'Burkina Faso',
    'Guinée',
    'Cameroun',
    'Gambie',
  ],
} as const;

export const STATS = [
  { value: '12+', label: "ans d'expérience" },
  { value: '7', label: 'pays de présence' },
  { value: '25', label: 'collaborateurs' },
  { value: '9', label: 'domaines de service' },
] as const;

export const TEAM_SECTION = {
  eyebrow: 'Notre équipe',
  title: 'Des talents engagés à vos côtés.',
  intro:
    "Une équipe pluridisciplinaire qui conçoit, développe et accompagne vos solutions au quotidien.",
} as const;
