/**
 * Builds a Stripe Elements `appearance` object from this app's own palette
 * instead of hardcoding a second copy of it. Reads Tailwind v4's generated
 * `--color-*` custom properties off :root, so a design-token change here is
 * automatic rather than a second edit in this file.
 */
function readColor(name, fallback) {
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

  return value || fallback;
}

function isDark() {
  return document.documentElement.classList.contains('dark');
}

export function buildAppearance() {
  const dark = isDark();
  const primary = readColor('--color-blue-600', '#2563eb');

  return {
    theme: dark ? 'night' : 'stripe',
    variables: {
      colorPrimary: primary,
      colorBackground: readColor(dark ? '--color-zinc-900' : '--color-white', dark ? '#18181b' : '#ffffff'),
      colorText: readColor(dark ? '--color-zinc-100' : '--color-zinc-900', dark ? '#f4f4f5' : '#18181b'),
      colorDanger: readColor('--color-red-600', '#dc2626'),
      colorIconTabSelected: primary,
      fontFamily: 'Instrument Sans, ui-sans-serif, system-ui, sans-serif',
      borderRadius: '0.5rem',
    },
    // The 'night' base theme's own selected-tab background is green,
    // independent of colorPrimary/colorIconTabSelected above — clashes with
    // the app's blue/purple brand the moment dark mode is on. The 'stripe'
    // (light) base theme doesn't have this problem, so it's left alone.
    rules: dark
      ? {
          '.Tab--selected': {
            backgroundColor: readColor('--color-zinc-800', '#27272a'),
            borderColor: primary,
          },
        }
      : {},
  };
}

/**
 * The app toggles dark mode by adding/removing a `.dark` class on <html>
 * (Flux's own @fluxAppearance mechanism) rather than firing a public event,
 * so a MutationObserver is the only theme-change signal that doesn't depend
 * on Flux's private internals. A Payment Element mounted once at load and
 * never re-themed reads visibly wrong the moment the user toggles.
 */
export function watchAppearance(onChange) {
  const observer = new MutationObserver(() => onChange(buildAppearance()));

  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class'],
  });

  return () => observer.disconnect();
}
