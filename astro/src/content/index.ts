/**
 * Couche de contenu du site : point d'entrée unique pour tout le contenu éditorial.
 *
 * Le contenu provient de l'instantané versionné `wp-content.json` (voir docs/contrat-contenu-wp.md),
 * lui-même alimenté par WordPress via `npm run content:pull`. Le build ne fait AUCUN appel réseau.
 *
 * Les gabarits importent les mêmes noms qu'avant (SERVICES, HERO, ABOUT_PAGE, SITE…) : le
 * branchement WordPress a changé la source, pas les composants.
 */

import raw from './wp-content.json';
import type {
  AboutExpertiseBlock,
  AboutHeaderBlock,
  AboutTeamSectionBlock,
  ContentDocument,
  Cta,
  HomeAboutBlock,
  HomeAboutView,
  HomeFinalCtaBlock,
  HomeProcessBlock,
  HomeServicesBlock,
  HomeSolutionsBlock,
  HomeWhyBlock,
  HeroBlock,
  HeroView,
  ImageRef,
  IndexPageBlock,
  NavItem,
  Phone,
  RealisationEntity,
  Secteur,
  ServiceEntity,
  TeamMemberEntity,
} from '../lib/wp/types';

const document = raw as unknown as ContentDocument;

if (!document || document.schemaVersion !== 1) {
  throw new Error(
    'Instantané de contenu manquant ou de version incompatible (src/content/wp-content.json). ' +
      'Exécuter : npm run content:pull (nécessite un accès réseau à WordPress).'
  );
}

export function getContent(): ContentDocument {
  return document;
}

/** URL d'une image, ou null si elle n'est pas renseignée. */
export function imageUrl(image: ImageRef | null | undefined): string | null {
  return image?.url ?? null;
}

/** Texte alternatif : celui du média WordPress, sinon le repli éditorial. */
export function imageAlt(
  image: ImageRef | null | undefined,
  fallback: string | null | undefined
): string {
  return image?.alt?.trim() || fallback?.trim() || '';
}

/* ------------------------------------------------------------------ *
 * Vues consommées par les gabarits
 * ------------------------------------------------------------------ */

export const SITE = {
  name: document.site.name,
  shortName: document.site.shortName,
  legalName: document.site.legalName,
  tagline: document.site.tagline,
  description: document.site.description,
  url: document.site.url,
  logo: document.site.logo?.url ?? '/brand/tsis-logo.png',
  logoAlt: imageAlt(document.site.logo, document.site.name),
  email: document.contact.email,
  address: document.contact.address,
  mapsUrl: document.contact.mapsUrl,
  defaultLocale: document.lang,
  locales: ['fr', 'en'] as const,
};

export const CONTACT = {
  whatsapp: document.contact.whatsapp,
  phones: document.contact.phones as Phone[],
};

export const SOCIAL = document.social;
export const NAV: NavItem[] = document.nav.primary;

/** Menu de pied de page ; à défaut, il reprend la navigation principale. */
export const NAV_FOOTER: NavItem[] = document.nav.footer?.length
  ? document.nav.footer
  : document.nav.primary;

export const PRIMARY_CTA: Cta = document.nav.primaryCta;

/** Libellé d'interface éditable, avec repli explicite pour ne jamais laisser un trou. */
export function ui(key: string, fallback: string): string {
  const value = document.ui?.[key];
  return typeof value === 'string' && value.trim() !== '' ? value : fallback;
}

export const UI: Record<string, string> = document.ui;

/** Convertit l'image objet du document en chemin utilisable par les composants. */
const resolveBlockImage = <T extends { image: ImageRef | null; imageAlt: string }>(
  block: T
): Omit<T, 'image'> & { image: string | null } => ({
  ...block,
  image: imageUrl(block.image),
  imageAlt: imageAlt(block.image, block.imageAlt),
});

/* Contenu de la page d'accueil */
export const HERO: HeroView = resolveBlockImage(document.pages.home.hero) as unknown as HeroView;
export const ABOUT: HomeAboutView = resolveBlockImage(
  document.pages.home.about
) as unknown as HomeAboutView;
export const SOLUTIONS: HomeSolutionsBlock = document.pages.home.solutions;
export const SERVICES_SECTION: HomeServicesBlock = document.pages.home.services;
export const WHY: HomeWhyBlock = document.pages.home.why;
export const PROCESS: HomeProcessBlock = document.pages.home.process;
export const FINAL_CTA: HomeFinalCtaBlock = document.pages.home.finalCta;

/* Contenu de la page À propos */
export const ABOUT_PAGE: AboutHeaderBlock = document.pages.about.header;
export const PILLARS: { title: string; text: string }[] = document.pages.about.pillars;
export const EXPERTISE: AboutExpertiseBlock = document.pages.about.expertise;
export const STATS: { value: string; label: string }[] = document.pages.about.stats;
export const TEAM_SECTION: AboutTeamSectionBlock = document.pages.about.teamSection;

/* En-têtes des pages d'index et de contact */
export const PAGES: {
  servicesIndex: IndexPageBlock;
  solutionsIndex: IndexPageBlock;
  contact: IndexPageBlock;
} = {
  servicesIndex: document.pages.servicesIndex,
  solutionsIndex: document.pages.solutionsIndex,
  contact: document.pages.contact,
};

export interface ServiceView {
  slug: string;
  title: string;
  excerpt: string;
  order: number;
}

export interface RealisationView {
  slug: string;
  title: string;
  excerpt: string;
  secteurs: string[];
  image: string | null;
  imageAlt: string;
}

export interface TeamMemberView {
  name: string;
  role: string;
  photo: string | null;
}

const secteurName = new Map<string, string>(document.secteurs.map((s) => [s.slug, s.name]));

/** Services triés par ordre d'affichage défini côté WordPress. */
export const SERVICES: ServiceView[] = [...document.services]
  .sort((a, b) => (a.order ?? 100) - (b.order ?? 100) || a.title.localeCompare(b.title, 'fr'))
  .map((service) => ({
    slug: service.slug,
    title: service.title,
    excerpt: service.excerpt,
    order: service.order ?? 100,
  }));

export const REALISATIONS: RealisationView[] = document.realisations.map((item) => ({
  slug: item.slug,
  title: item.title,
  excerpt: item.excerpt,
  secteurs: item.secteurs.map((slug) => secteurName.get(slug) ?? slug),
  image: item.image?.url ?? null,
  imageAlt: imageAlt(item.image, item.title),
}));

export const SECTEURS: Secteur[] = document.secteurs;

/** Services mis en avant en pied de page ; à défaut, les quatre premiers services du catalogue. */
export const NAV_FOOTER_SERVICES: NavItem[] = document.nav.footerServices?.length
  ? document.nav.footerServices
  : SERVICES.slice(0, 4).map((service) => ({
      label: service.title,
      href: `/services/${service.slug}/`,
    }));

/** Membres publiés sur le site, dans l'ordre défini côté WordPress. */
export const TEAM: TeamMemberView[] = [...document.team]
  .filter((member) => member.name && member.name.trim() !== '')
  .sort((a, b) => (a.order ?? 100) - (b.order ?? 100))
  .map((member) => ({
    name: member.name,
    role: member.role,
    photo: member.photo?.url ?? null,
  }));

export function getServiceEntity(slug: string): ServiceEntity | undefined {
  return document.services.find((service) => service.slug === slug);
}

export function getRealisationEntity(slug: string): RealisationEntity | undefined {
  return document.realisations.find((item) => item.slug === slug);
}

export function getTeamEntities(): TeamMemberEntity[] {
  return document.team;
}
