/** Text as HTML: translations may hold `<` or `&`. Merge tags are left as they are. */
const escapeText = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/**
 * A Send email message (HTML) from its paragraphs of plain text: one `<p>` each, line breaks kept
 * as `<br>`. A paragraph that is only a block tag (e.g. `{{ steps.coupon.box }}`) is drawn as the block.
 */
export const emailMessage = (paragraphs: string[]) =>
  paragraphs.map((text) => `<p>${escapeText(text).replaceAll('\n', '<br>\n')}</p>`).join('\n');
