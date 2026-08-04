/**
 * Fixtures that mimic what the AI returns for a long landing-page prompt.
 *
 * The editor freeze only showed up for snapshots of this shape and size, so the
 * builder is parameterised: raise `sections` to push past the threshold, lower
 * it to find where the freeze stops.
 */

const HERO = `
  <section class="relative overflow-hidden bg-gradient-to-b from-red-50 via-white to-emerald-50">
    <div class="mx-auto flex max-w-6xl flex-col gap-10 px-6 py-16 md:flex-row md:items-center md:py-24">
      <div class="flex-1 space-y-6">
        <span class="inline-flex items-center rounded-full bg-red-100 px-4 py-2 text-sm font-semibold text-red-700">産地直送の新鮮なりんご</span>
        <div class="space-y-4">
          <h1 class="text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl lg:text-6xl">リンゴの販売ページ</h1>
          <p class="text-lg leading-relaxed text-slate-600">朝採れのりんごを、みずみずしさそのままにお届けします。</p>
        </div>
        <div class="flex flex-col gap-3 sm:flex-row">
          <a href="#buy" class="inline-flex items-center justify-center rounded-lg bg-red-600 px-6 py-3 text-base font-semibold text-white shadow-lg transition hover:bg-red-700">今すぐ購入する</a>
          <a href="#contact" class="inline-flex items-center justify-center rounded-lg border border-slate-300 px-6 py-3 text-base font-semibold text-slate-700 transition hover:bg-slate-50">お問い合わせ</a>
        </div>
      </div>
      <div class="flex-1">
        <div class="aspect-[4/3] w-full rounded-2xl bg-gradient-to-br from-red-200 via-red-100 to-emerald-100 shadow-xl"></div>
      </div>
    </div>
  </section>`;

const featureSection = (index: number) => `
  <section class="mx-auto max-w-6xl px-6 py-12 sm:px-8 lg:py-16">
    <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
      <div class="space-y-4">
        <h2 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">りんごの魅力 ${index}</h2>
        <p class="text-base leading-relaxed text-slate-600">寒暖差の大きい土地で育ったりんごは、甘みと酸味のバランスが際立ちます。</p>
        <ul class="space-y-3">
          <li class="flex items-start gap-3"><span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-emerald-500"></span><span class="text-slate-700">朝採れをその日のうちに発送</span></li>
          <li class="flex items-start gap-3"><span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-emerald-500"></span><span class="text-slate-700">農薬をできる限り抑えた栽培</span></li>
          <li class="flex items-start gap-3"><span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-emerald-500"></span><span class="text-slate-700">贈答用の化粧箱にも対応</span></li>
        </ul>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"><p class="text-sm font-semibold text-slate-900">糖度</p><p class="mt-1 text-2xl font-bold text-red-600">14度</p></div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"><p class="text-sm font-semibold text-slate-900">産地</p><p class="mt-1 text-2xl font-bold text-emerald-600">長野県</p></div>
      </div>
    </div>
  </section>`;

const CTA = `
  <section id="buy" class="bg-slate-900 py-16">
    <div class="mx-auto flex max-w-4xl flex-col items-center gap-6 px-6 text-center">
      <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">季節限定のりんごをご自宅へ</h2>
      <p class="max-w-2xl text-base leading-relaxed text-slate-300">数量に限りがあります。お早めにご注文ください。</p>
      <a href="#contact" class="inline-flex items-center justify-center rounded-lg bg-red-500 px-8 py-4 text-lg font-semibold text-white shadow-xl transition hover:bg-red-400">購入手続きへ進む</a>
    </div>
  </section>`;

/** Build a landing page of roughly the size the AI produces (default ≈6 KB). */
export function buildTailwindLandingPage(sections = 3): string {
  const body = Array.from({ length: sections }, (_, index) => featureSection(index + 1)).join('\n');
  return `<main class="min-h-screen bg-white text-slate-800">${HERO}\n${body}\n${CTA}\n</main>`;
}

export const TAILWIND_CSS_SOURCE = '@import "tailwindcss";\n\n@theme {\n  /* ... */\n}\n';

/** A snapshot shaped exactly like the one the AI handoff applies. */
export function buildAiSnapshot(sections = 3) {
  return {
    html: buildTailwindLandingPage(sections),
    customHead: '',
    css: TAILWIND_CSS_SOURCE,
    js: '',
    jsMode: 'classic' as const,
    baseHash: '',
  };
}
