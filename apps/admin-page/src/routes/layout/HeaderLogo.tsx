export default function HeaderLogo() {
  return (
    <div className="flex h-full shrink-0 bg-[#FFF5DB] px-0.75 pt-0.75 dark:bg-[#FFF5DB]/15">
      <img
        src={`${window.yayMatikMeta.plugin.pluginUrl}assets/images/yaymatik-logo.png`}
        alt="YayMatik"
        className="size-12.5"
      />
    </div>
  );
}
