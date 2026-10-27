// Ikon garis sederhana (24x24), warna mengikuti teks
const PATHS = {
  search: <><circle cx="11" cy="11" r="6.5" /><path d="m16 16 4.5 4.5" /></>,
  plus: <path d="M12 5v14M5 12h14" />,
  download: <path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 19.5h14" />,
  edit: <path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3Zm9.5-13.5 3 3" />,
  trash: <path d="M5 7h14M10 7V5h4v2m-8 0 .8 12h8.4L17 7M10 11v5m4-5v5" />,
  back: <path d="M19 12H5m0 0 5-5m-5 5 5 5" />,
  up: <path d="M12 19V5m0 0-5 5m5-5 5 5" />,
  down: <path d="M12 5v14m0 0-5-5m5 5 5-5" />,
  x: <path d="M6 6l12 12M18 6 6 18" />,
  check: <path d="m5 12.5 4.5 4.5L19 7.5" />,
  chevron: <path d="m6 9 6 6 6-6" />,
  copy: <><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V6a2 2 0 0 1 2-2h9" /></>,
}

export default function Icon({ name, size = 18 }) {
  return (
    <svg viewBox="0 0 24 24" width={size} height={size} fill="none" stroke="currentColor"
      strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {PATHS[name]}
    </svg>
  )
}
