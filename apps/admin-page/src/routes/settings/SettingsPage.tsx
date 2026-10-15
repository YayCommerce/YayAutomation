import { __ } from '@wordpress/i18n';

import { PageCard, PageTitle } from '@/components/widget/PageWidget';

export default function SettingsPage() {
  return (
    <PageCard>
      <PageTitle>{__('Settings', 'yaymatik')}</PageTitle>
    </PageCard>
  );
}
