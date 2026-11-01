import { useEffect, useState } from 'react';
import { Outlet } from 'react-router';

import { cn } from '@/lib/utils';
import { Toaster } from '@/components/ui/sonner';
import HeaderLogo from './HeaderLogo';
import HeaderMenu from './HeaderMenu';
import ThemeToggle from './ThemeToggle';

export default function Layout() {
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 0);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  return (
    <>
      {/* WordPress sets the admin bar height var (32px, 46px at 782px and below); at 600px and below the bar scrolls away. z-40 keeps every overlay (z-50 and up) above. */}
      <div
        className={cn(
          'bg-background border-border sticky top-0 z-40 flex h-13.5 items-center justify-between gap-1.5 border-b py-0 pr-3 transition-shadow duration-300 min-[601px]:top-(--wp-admin--admin-bar--height,32px) lg:gap-5 lg:pr-6',
          scrolled && 'shadow-header-shadow shadow-md',
        )}
      >
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
