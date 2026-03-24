export type TemplateMode = 'default' | 'standalone' | 'theme';
export type DefaultTemplateMode = 'standalone' | 'theme';

export function resolveTemplateMode(value?: string): TemplateMode {
  if (value === 'standalone' || value === 'theme' || value === 'default') {
    return value;
  }
  return 'default';
}

export function resolveDefaultTemplateMode(value?: string): DefaultTemplateMode {
  if (value === 'standalone' || value === 'theme') {
    return value;
  }
  return 'theme';
}
