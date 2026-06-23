export type TemplateMarket = 'jp' | 'en';

export type TemplateTier = 'free' | 'pro';

export type TemplateSummary = {
  id: string;
  title: string;
  description: string;
  category: string;
  market: TemplateMarket;
  tier: TemplateTier;
  thumbnailUrl: string;
  requiresTailwind: boolean;
  available: boolean;
  version: string;
};

export type TemplateCatalogResponse = {
  ok?: boolean;
  error?: string;
  templates?: TemplateSummary[];
};
