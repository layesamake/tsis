/**
 * Contenu de la page d'accueil.
 * Structure volontairement alignée sur ce que produira WordPress (sections ACF),
 * afin que le passage à l'API headless ne change que la source, pas les composants.
 */

export const HERO = {
  eyebrow: 'Votre partenaire digital au Sénégal',
  title: 'Des solutions digitales qui font avancer votre entreprise.',
  text: 'Logiciels sur mesure, applications et infrastructures IT : nous transformons vos besoins en solutions concrètes.',
  chips: ['Sur mesure', 'Accompagnement', 'Support'],
  image: '/uploads/hero-computer-1.png',
  imageAlt: 'Solutions digitales TSIS',
  primaryCta: { label: 'Demandez un devis', href: '/contact/' },
  secondaryCta: { label: 'Découvrir nos solutions', href: '#solutions' },
} as const;

export const ABOUT = {
  eyebrow: 'À propos de TSIS',
  title: 'Des solutions technologiques pensées pour vos réalités.',
  text: "Touba Solution Informatique conçoit des logiciels, sites web et applications mobiles sur mesure. Nous associons IA, cybersécurité, automatisation et expertise métier pour améliorer l'efficacité, la visibilité et la croissance de votre organisation.",
  note: "Notre expertise couvre le commerce, la santé, les ressources humaines, l'éducation et l'industrie.",
  highlights: ['Logiciels sur mesure', 'Expertise multi-secteurs'],
  image: '/uploads/boubacare.avif',
  imageAlt: 'Équipe Touba Solution Informatique',
  cta: { label: 'En savoir plus', href: '/a-propos/' },
} as const;

export const SOLUTIONS = {
  eyebrow: 'Nos solutions',
  title: 'Des solutions pensées pour vos réalités.',
  intro:
    'Découvrez quelques-unes de nos réalisations digitales, conçues pour accompagner la croissance des entreprises et organisations.',
  /** Slugs de réalisations mis en avant (voir data/realisations.ts). */
  featured: ['securite-pointage', 'sunu-flix', 'formation-entreprise'],
  cta: { label: 'Voir toutes nos solutions', href: '/nos-solutions/' },
} as const;

export const SERVICES_SECTION = {
  eyebrow: 'Nos services',
  title: 'Une expertise complète pour faire évoluer votre activité.',
  intro:
    'De la conception à l’accompagnement, nous mettons nos compétences technologiques au service de vos objectifs.',
  cta: { label: 'Découvrir tous nos services', href: '/nos-services/' },
} as const;

export const WHY = {
  eyebrow: 'Pourquoi choisir TSIS ?',
  title: 'Un partenaire engagé à vos côtés.',
  intro:
    'Nous combinons expertise technique, compréhension de votre métier et accompagnement humain pour transformer vos idées en résultats concrets.',
  items: [
    {
      title: 'Solutions sur mesure',
      text: 'Des outils conçus autour de vos objectifs, de votre organisation et de vos contraintes réelles.',
    },
    {
      title: 'Fiabilité & sécurité',
      text: 'Des solutions robustes, sécurisées et pensées pour évoluer durablement avec votre activité.',
    },
    {
      title: 'Expertise locale',
      text: 'Une équipe proche de vous, qui comprend les réalités des entreprises et organisations sénégalaises.',
    },
    {
      title: 'Accompagnement durable',
      text: 'Du cadrage au support, nous restons présents à chaque étape de votre transformation digitale.',
    },
  ],
  cta: { label: 'Échangeons sur votre projet', href: '/contact/' },
} as const;

export const PROCESS = {
  eyebrow: "De l'idée à l'impact",
  title: 'Votre vision devient une solution qui crée de la valeur.',
  intro:
    'Une méthode simple, collaborative et centrée sur des résultats concrets pour votre organisation.',
  steps: [
    { num: '01', title: 'Écouter', text: 'Comprendre vos besoins, votre contexte et vos objectifs prioritaires.' },
    { num: '02', title: 'Concevoir', text: 'Imaginer une solution pertinente, intuitive et adaptée à vos utilisateurs.' },
    { num: '03', title: 'Développer', text: 'Construire, tester et déployer une solution fiable, sécurisée et performante.' },
    { num: '04', title: 'Faire évoluer', text: 'Vous accompagner dans la durée, mesurer les résultats et améliorer en continu.' },
  ],
} as const;

export const FINAL_CTA = {
  eyebrow: 'Faisons avancer votre projet',
  title: 'Une idée, un besoin ? Parlons-en.',
  text: 'Présentez-nous vos objectifs et les défis de votre activité. Ensemble, identifions la solution digitale adaptée à votre organisation.',
  cta: { label: 'Parlons de votre projet', href: '/contact/' },
} as const;
