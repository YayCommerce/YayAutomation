import { Workflow } from 'lucide-react';

export default function HeaderLogo() {
  return (
    <div className="flex h-full items-center gap-2 pl-4 lg:pl-6">
      <span className="bg-primary text-primary-foreground flex size-7 items-center justify-center rounded-md">
        <Workflow className="size-4" />
      </span>
      <span className="text-base font-semibold max-md:hidden">YayMatik</span>
    </div>
  );
}
