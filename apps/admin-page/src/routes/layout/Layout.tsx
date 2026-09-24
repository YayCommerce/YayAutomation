import { Outlet } from 'react-router';

import { Toaster } from '@/components/ui/sonner';
import HeaderLogo from './HeaderLogo';
import HeaderMenu from './HeaderMenu';
import ThemeToggle from './ThemeToggle';

export default function Layout() {
  return (
    <>
      <div className="bg-background relative flex h-13.5 items-center justify-between gap-4 py-0 pr-3 sm:gap-5 lg:pr-6">
        <HeaderLogo />
        <HeaderMenu />
        <div className="flex items-center">
          <ThemeToggle />
          <div id="yay-header-actions" />
        </div>
      </div>
      <div className="@container/main">
        <Outlet />
      </div>
      <Toaster />
    </>
  );
}
