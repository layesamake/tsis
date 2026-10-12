/**
 * Configuration centrale du site TSIS.
 * Ces valeurs seront à terme alimentées par WordPress (options globales / ACF).
 * Pour l'instant elles servent de source unique pour le header, le footer et le SEO.
 */

export const SITE = {
  name: 'Touba Solution Informatique',
  shortName: 'TSIS',
  legalName: 'Touba Solution Informatique',
  tagline: 'Votre partenaire digital au Sénégal',
  description:
    'Basée au Sénégal, Touba Solution Informatique conçoit des applications web et mobiles, logiciels ERP/CRM et solutions numériques sur mesure pour accompagner la transformation digitale des entreprises et institutions en Afrique.',
  /** Domaine de travail. À basculer vers https://tsis.sn lors du transfert de domaine. */
  url: 'https://astro.tsis.sn',
  defaultLocale: 'fr',
  locales: ['fr', 'en'] as const,
  email: 'contact@tsis.sn',
  address: 'Arrêt BRT Scat Urbam, Dakar, Sénégal',
  /** URL Google Maps du site d'origine. */
  mapsUrl: 'https://maps.app.goo.gl/x1heEpd86rZTHvov5',
} as const;

export const CONTACT = {
  whatsapp: 'https://wa.me/221782254040',
  phones: [
    { label: '+221 78 225 40 40', href: 'tel:+221782254040', whatsapp: true },
    { label: '+221 78 698 18 18', href: 'tel:+221786981818', whatsapp: false },
  ],
} as const;

export const SOCIAL = [
  { label: 'Facebook', href: 'https://www.facebook.com/ToubaSolutionInformatique' },
  { label: 'LinkedIn', href: 'https://www.linkedin.com/company/touba-solution-informatique' },
  { label: 'Instagram', href: 'https://www.instagram.com/touba_solution_informatique/' },
  { label: 'TikTok', href: 'https://www.tiktok.com/@touba.solution' },
] as const;

export const NAV = [
  { label: 'Accueil', href: '/' },
  { label: 'À propos', href: '/a-propos/' },
  { label: 'Nos services', href: '/nos-services/' },
  { label: 'Nos solutions', href: '/nos-solutions/' },
  { label: 'Contact', href: '/contact/' },
] as const;

export const PRIMARY_CTA = { label: 'Demander un devis', href: '/contact/' } as const;
