// Контакты, адрес, часы работы и юрлицо подтверждены клиентом (2026-10-11).
// email — всё ещё плейсхолдер, ждём настоящий адрес. Инстаграма у компании нет.

export const site = {
  companyName: "QAZ Pest Control",
  legalName: "ТОО «Yand Company»",
  tagline: "Профессиональная борьба с вредителями",
  phone: "+7 705-763-49-69",
  phoneHref: "tel:+77057634969",
  email: "info@dezgarant.kz",
  address: "Казахстан, г. Алматы, ул. Бекмаханова 93/2",
  hours: [
    { days: "Пн–Пт", time: "9:00–18:00" },
    { days: "Сб, Вс", time: "выходной" },
  ],
  // Год основания — уточнить у клиента; пока null, фраза "на рынке с N года" не показывается.
  sinceYear: null as number | null,
  leadEmailTarget: "in4qpc@gmail.com",
};

export const navLinks = [
  { label: "Продукция", href: "#categories" },
  { label: "Бренды", href: "#brands" },
  { label: "Услуги", href: "#services" },
  { label: "О компании", href: "#about" },
  { label: "Доставка и оплата", href: "#delivery" },
  { label: "Контакты", href: "#contacts" },
];
