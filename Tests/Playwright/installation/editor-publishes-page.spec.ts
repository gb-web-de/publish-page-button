import { test, expect, type Frame, type Page } from '@playwright/test'

/*
 * Die Installations-Anleitung als Weg durch den Browser: eine Installation, die nur `publishpage:setup`
 * gesehen hat, und ein Redakteur, den dieser Lauf angelegt (oder in eine Gruppe aufgenommen) hat.
 *
 * Geprueft wird, was ein Redakteur zuerst merken wuerde, wenn das Setup etwas falsch gemacht haette: Er
 * landet in Live statt im Workspace, der Knopf fehlt im Seitenmodul, oder der Knopf sagt "Keine
 * Berechtigung". Als Admin sieht man nichts davon - deshalb meldet sich der Test als der Redakteur an.
 *
 * Laeuft nur, wenn PUBLISHPAGE_SETUP_EDITOR gesetzt ist:
 *
 *   PUBLISHPAGE_SETUP_EDITOR=redakteur
 *   PUBLISHPAGE_SETUP_PASSWORD=...          (Standard Password.1)
 *   PUBLISHPAGE_SETUP_WORKSPACE=Redaktion   (optional: der erwartete Workspace)
 *   PUBLISHPAGE_TEST_PAGE_ID=1
 *   PUBLISHPAGE_NEW_TITLE=...               (optional: neuer Seitentitel, den die CI danach live prueft)
 */
const editor = process.env.PUBLISHPAGE_SETUP_EDITOR
const password = process.env.PUBLISHPAGE_SETUP_PASSWORD ?? 'Password.1'
const workspace = process.env.PUBLISHPAGE_SETUP_WORKSPACE
const pageId = process.env.PUBLISHPAGE_TEST_PAGE_ID ?? '1'
const newTitle = process.env.PUBLISHPAGE_NEW_TITLE ?? `Veroeffentlicht per Knopf ${Date.now()}`

async function openPageModule(page: Page): Promise<Frame> {
  await page.goto(`/typo3/module/web/layout?id=${pageId}`)
  // Das Modul rendert im Inhalts-iframe des Backends, das erst nach dem Dokument entsteht.
  const iframe = await page.waitForSelector('iframe[name="list_frame"]', { state: 'attached' })
  const frame = await iframe.contentFrame()
  if (frame === null) {
    throw new Error('Das Inhalts-iframe des Backends (list_frame) kam nie.')
  }
  await frame.waitForLoadState()

  return frame
}

function publishButton(frame: Frame) {
  return frame.locator('a[title="Publish page"], a[title="Seite veröffentlichen"], a[title="Seite veroeffentlichen"]')
}

test.describe('ein Redakteur nach publishpage:setup', () => {
  test.skip(!editor, 'PUBLISHPAGE_SETUP_EDITOR ist nicht gesetzt - siehe Documentation/Installation')

  test.beforeEach(async ({ page }) => {
    await page.goto('/typo3/')
    await page.getByLabel(/username|benutzername/i).fill(editor ?? '')
    await page.getByLabel(/password|passwort/i).fill(password)
    await page.getByRole('button', { name: /^(login|anmelden)$/i }).click()
    await expect(page.locator('#typo3-module-menu, .modulemenu')).toBeVisible({ timeout: 15000 })
  })

  /*
   * Die Einstellung, an der alles haengt: Wer Live bearbeiten darf, landet in Live, sein Speichern ist
   * sofort online, und der Knopf erscheint gar nicht erst.
   */
  test('arbeitet im Workspace, nicht in Live', async ({ page }) => {
    await page.waitForFunction(() => (window as any).TYPO3?.WorkspaceState !== undefined)
    const current = await page.evaluate(async () => (window as any).TYPO3.WorkspaceState.getCurrentWorkspace())

    expect(current.id, 'der Redakteur ist in Live gelandet').toBeGreaterThan(0)
    if (workspace) {
      expect(current.title).toBe(workspace)
    }
  })

  test('sieht den Knopf im Seitenmodul', async ({ page }) => {
    const frame = await openPageModule(page)

    await expect(publishButton(frame)).toBeVisible()
  })

  /*
   * Der ganze Weg: Seitentitel im Workspace aendern, Knopf druecken, die Aenderung ist live. "Keine
   * Berechtigung" hiesse: Die Gruppe besitzt den Workspace nicht, oder er erlaubt Veroeffentlichen nur aus
   * der Publish-Stage.
   */
  test('aendert eine Seite und bringt sie per Knopf live', async ({ page }) => {
    let frame = await openPageModule(page)
    // Der Knopf "Seiteneigenschaften bearbeiten" oeffnet ein Seitenpanel; sein volles Formular steht in edit-url.
    const editUrl = await frame.locator('typo3-backend-contextual-record-edit-trigger[context="pages"]').first().getAttribute('edit-url')
    expect(editUrl, 'kein Bearbeiten-Knopf fuer die Seite').toBeTruthy()
    await frame.goto(editUrl ?? '')

    const title = frame.locator(`input[data-formengine-input-name="data[pages][${pageId}][title]"]`)
    await expect(title).toBeVisible({ timeout: 15000 })
    await title.fill(newTitle)
    await frame.locator('button[name="_savedok"]').first().click()
    await expect(frame.locator(`input[data-formengine-input-name="data[pages][${pageId}][title]"]`)).toHaveValue(newTitle, { timeout: 15000 })

    frame = await openPageModule(page)
    await publishButton(frame).click()

    // Die Flash-Message steht nach der Weiterleitung oben im Seitenmodul.
    await expect(frame.getByText(/Alle (A|ä)nderungen dieser Seite sind jetzt live|All changes on this page are now live/i)).toBeVisible({ timeout: 15000 })
    await expect(frame.getByText(/Keine Berechtigung|No permission/i)).toHaveCount(0)
  })
})
