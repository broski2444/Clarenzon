export default async function run(page) {
  await page.context().addCookies([{
    name: 'CLARSESS_warehouse_head',
    value: '77hakotrqlbuqmm03t4pbqsgs0',
    url: 'http://localhost/CAPSTONE/',
    httpOnly: true,
    sameSite: 'Lax',
  }]);

  const assetStatus = [];
  page.on('response', (res) => {
    const url = res.url();
    if (url.includes('/css/') || url.includes('bootstrap') || url.includes('fonts.googleapis')) {
      assetStatus.push({ status: res.status(), url });
    }
  });

  const resp = await page.goto('http://localhost/CAPSTONE/warehouse_head/dashboard.php', {
    waitUntil: 'domcontentloaded',
    timeout: 90000,
  });
  await page.waitForSelector('.sidebar', { timeout: 20000 });
  await page.waitForTimeout(800);

  const info = await page.evaluate(() => {
    const side = document.querySelector('.sidebar');
    const cs = side ? getComputedStyle(side) : null;
    const card = document.querySelector('.stat-card');
    const ccs = card ? getComputedStyle(card) : null;
    const welcome = document.querySelector('.page-header h1, .welcome-title, h1');
    return {
      title: document.title,
      sidebarBg: cs ? cs.backgroundColor : null,
      sidebarWidth: cs ? cs.width : null,
      sidebarColor: cs ? cs.color : null,
      cardCount: document.querySelectorAll('.stat-card').length,
      cardBg: ccs ? ccs.backgroundColor : null,
      cardRadius: ccs ? ccs.borderRadius : null,
      bodyFont: getComputedStyle(document.body).fontFamily,
      bodyBg: getComputedStyle(document.body).backgroundColor,
      heading: welcome ? welcome.textContent.trim().slice(0, 80) : null,
      stylesheets: [...document.querySelectorAll('link[rel=stylesheet]')].map((l) => l.href),
    };
  });

  await page.screenshot({
    path: 'C:/laragon/www/CAPSTONE/_wh_dash_check.png',
    fullPage: false,
  });

  return {
    httpStatus: resp ? resp.status() : null,
    assetStatus,
    info,
  };
}
