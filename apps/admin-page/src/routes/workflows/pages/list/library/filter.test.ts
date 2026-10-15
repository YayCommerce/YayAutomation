import { describe, expect, it } from 'vitest';

import { usesAnyOf } from './filter';

describe('usesAnyOf', () => {
  it('shows every template while no plugin is chosen', () => {
    expect(usesAnyOf(['woocommerce'], [])).toBe(true);
  });

  it('matches a template that uses any chosen plugin (OR), not all of them', () => {
    expect(usesAnyOf(['woocommerce'], ['woocommerce', 'yaymatik'])).toBe(true);
    expect(usesAnyOf(['yaymatik'], ['woocommerce'])).toBe(false);
  });
});
