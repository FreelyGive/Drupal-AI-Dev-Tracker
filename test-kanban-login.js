const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  
  try {
    console.log('Navigating to login page...');
    await page.goto('https://drupalcmsaitest1.ddev.site/user/login');
    
    console.log('Filling in login credentials...');
    await page.fill('#edit-name', 'admin');
    await page.fill('#edit-pass', 'admin');
    
    console.log('Submitting login form...');
    await page.click('#edit-submit');
    
    // Wait for login to complete
    await page.waitForLoadState('networkidle');
    
    console.log('Navigating to kanban page...');
    await page.goto('https://drupalcmsaitest1.ddev.site/ai-dashboard/priority-kanban');
    
    // Wait for page to load
    await page.waitForSelector('.ai-priority-kanban', { timeout: 10000 });
    
    console.log('Taking screenshot...');
    await page.screenshot({ path: 'kanban-page.png', fullPage: true });
    
    // Get page title and check for elements
    const title = await page.title();
    console.log('Page title:', title);
    
    // Check if kanban board is present
    const kanbanExists = await page.$('.ai-priority-kanban');
    console.log('Kanban board element found:', !!kanbanExists);
    
    // Count columns
    const columnCount = await page.$$eval('.kanban-column', cols => cols.length);
    console.log('Number of columns:', columnCount);
    
    // Check if toggle button exists
    const toggleExists = await page.$('#optional-columns-toggle');
    console.log('Optional columns toggle found:', !!toggleExists);
    
    // Get column titles
    const columnTitles = await page.$$eval('.column-title', titles => 
      titles.map(title => title.textContent.trim())
    );
    console.log('Column titles:', columnTitles);
    
    console.log('Screenshot saved as kanban-page.png');
    
  } catch (error) {
    console.error('Error:', error.message);
    
    // Take error screenshot
    try {
      await page.screenshot({ path: 'error-page.png', fullPage: true });
      console.log('Error screenshot saved as error-page.png');
    } catch (screenshotError) {
      console.error('Could not take error screenshot:', screenshotError.message);
    }
  }
  
  await browser.close();
})();