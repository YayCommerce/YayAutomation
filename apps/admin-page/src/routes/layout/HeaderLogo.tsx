export default function HeaderLogo() {
  return (
    <div className="bg-logo-tile flex h-full shrink-0 px-0.75 pt-0.75">
      <img
        src={`${window.yayMatikMeta.plugin.pluginUrl}assets/images/yaymatik-logo.png`}
        alt="YayMatik"
        className="size-12.5"
      />
    </div>
  );
}
