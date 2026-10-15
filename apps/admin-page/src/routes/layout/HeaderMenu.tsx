import { Settings, Workflow } from 'lucide-react';
import { NavLink } from 'react-router';
import { __ } from '@wordpress/i18n';

import { HeaderNavMenuItem, HeaderNavMenuList } from '@/components/ui/navmenu-header';

export default function HeaderMenu() {
  return (
    <HeaderNavMenuList>
      <HeaderNavMenuItem asChild>
        <NavLink to="/workflows">
          <Workflow className="size-4 stroke-2" />
          <span className="max-sm:hidden">{__('Workflows', 'yaymatik')}</span>
        </NavLink>
      </HeaderNavMenuItem>
      <HeaderNavMenuItem asChild>
        <NavLink to="/settings">
          <Settings className="size-4 stroke-2" />
          <span className="max-sm:hidden">{__('Settings', 'yaymatik')}</span>
        </NavLink>
      </HeaderNavMenuItem>
    </HeaderNavMenuList>
  );
}
