import { createTemplateRegistry } from './core/registry';
import highValueOrderAlert from './high-value-order-alert';

/**
 * Every template in the library. To add one: create its folder (named after the template id) with an
 * `index.ts` that default-exports `defineTemplate({...})`, and list it here.
 */
export const { templates, findTemplate } = createTemplateRegistry([highValueOrderAlert]);
