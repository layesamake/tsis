/**
 * Types du document de contenu produit par WordPress (ou par l'instantané de repli).
 * Référence : docs/contrat-contenu-wp.md §4 et §5.
 */

export interface ImageRef {
  url: string;
  width: number | null;
  height: number | null;
  alt: string | null;
}

export interface Cta {
  label: string;
  href: string;
}

export interface Phone {
  label: string;
  href: string;
  whatsapp: boolean;
}

export interface NavItem {
  label: string;
  href: string;
  children?: NavItem[];
}

export interface Secteur {
  slug: string;
  name: string;
  count: number;
  description: string | null;
}

export interface ServiceEntity {
  slug: string;
  title: string;
  excerpt: string;
  order: number | null;
  image: ImageRef | null;
  icon: ImageRef | null;
  body: string | null;
  features: { title: string; text: string }[];
  seo: { title: string | null; description: string | null };
}

export interface RealisationEntity {
  slug: string;
  title: string;
  excerpt: string;
  secteurs: string[];
  image: ImageRef | null;
  client: string | null;
  year: string | null;
  body: string | null;
  features?: { title: string; text: string }[];
  results: { label: string; value: string }[];
  gallery: ImageRef[];
  /** Slugs des services liés (`projet_services`), convertis en slugs par le pont. */
  relatedServices?: string[];
  demoUrl?: string | null;
  testimonial?: { quote: string; name: string; role: string } | null;
  /** Réalisation mise en avant sur l'accueil (`projet_accueil`). */
  featured?: boolean;
  seo: { title: string | null; description: string | null };
}

export interface TeamMemberEntity {
  name: string;
  role: string;
  photo: ImageRef | null;
  order: number | null;
}

/* --- Blocs éditoriaux (document) --- */

export interface HeroBlock {
  eyebrow: string;
  title: string;
  text: string;
  chips: string[];
  image: ImageRef | null;
  imageAlt: string;
  primaryCta: Cta;
  secondaryCta: Cta;
}

export interface HomeAboutBlock {
  eyebrow: string;
  title: string;
  text: string;
  note: string;
  highlights: string[];
  image: ImageRef | null;
  imageAlt: string;
  cta: Cta;
}

export interface HomeSolutionsBlock {
  eyebrow: string;
  title: string;
  intro: string;
  /** Slugs des réalisations mises en avant sur l'accueil. */
  featured: string[];
  cta: Cta;
}

export interface HomeServicesBlock {
  eyebrow: string;
  title: string;
  intro: string;
  cta: Cta;
}

export interface HomeWhyBlock {
  eyebrow: string;
  title: string;
  intro: string;
  items: { title: string; text: string }[];
  cta: Cta;
}

export interface HomeProcessBlock {
  eyebrow: string;
  title: string;
  intro: string;
  steps: { num: string; title: string; text: string }[];
}

export interface HomeFinalCtaBlock {
  eyebrow: string;
  title: string;
  text: string;
  cta: Cta;
}

export interface AboutHeaderBlock {
  eyebrow: string;
  title: string;
  intro: string;
  badge: string;
}

export interface AboutExpertiseBlock {
  eyebrow: string;
  title: string;
  paragraphs: string[];
  countries: string[];
}

export interface AboutTeamSectionBlock {
  eyebrow: string;
  title: string;
  intro: string;
}

export interface IndexPageBlock {
  eyebrow: string;
  title: string;
  intro: string;
  metaTitle: string;
  metaDescription: string;
}

export interface ContentDocument {
  schemaVersion: number;
  generatedAt: string;
  lang: string;
  /** `seed` (contenu historique du dépôt), `rest` (REST public) ou `wordpress` (greffon). */
  source: 'seed' | 'rest' | 'wordpress' | string;
  site: {
    name: string;
    shortName: string;
    legalName: string;
    tagline: string;
    description: string;
    url: string;
    logo: ImageRef | null;
  };
  contact: {
    email: string;
    address: string;
    mapsUrl: string | null;
    whatsapp: string | null;
    phones: Phone[];
  };
  social: { label: string; href: string }[];
  nav: { primary: NavItem[]; footer: NavItem[]; footerServices: NavItem[]; primaryCta: Cta };
  ui: Record<string, string>;
  pages: {
    home: {
      hero: HeroBlock;
      about: HomeAboutBlock;
      solutions: HomeSolutionsBlock;
      services: HomeServicesBlock;
      why: HomeWhyBlock;
      process: HomeProcessBlock;
      finalCta: HomeFinalCtaBlock;
    };
    about: {
      header: AboutHeaderBlock;
      pillars: { title: string; text: string }[];
      expertise: AboutExpertiseBlock;
      stats: { value: string; label: string }[];
      teamSection: AboutTeamSectionBlock;
    };
    servicesIndex: IndexPageBlock;
    solutionsIndex: IndexPageBlock;
    contact: IndexPageBlock;
  };
  services: ServiceEntity[];
  realisations: RealisationEntity[];
  secteurs: Secteur[];
  team: TeamMemberEntity[];
}

/* --- Vues exposées aux gabarits (images résolues en chaîne pour les composants) --- */

export type HeroView = Omit<HeroBlock, 'image'> & { image: string | null };
export type HomeAboutView = Omit<HomeAboutBlock, 'image'> & { image: string | null };
