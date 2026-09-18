/**
 * CHK-003-BROWSER: checkout -> composition -> section synchronization.
 *
 * Usage:
 * WCCS_ORIGIN='http://wpagf.dvl.to:8080' \
 * WCCS_COOKIE='name=value' WCCS_AUTH_COOKIE='name=value' \
 *   node tests/browser/checkout-section-context-flow.mjs
 *
 * The flow creates one uniquely named alternate checkout and one uniquely named section,
 * verifies the selected composition and section in the browser and in the saved draft, then
 * removes the temporary checkout. It intentionally opens the dedicated `section=checkouts`
 * entry point: the specialized entry must not make a selected alternate profile stale.
 */
import { chromium } from "playwright";

const origin = process.env.WCCS_ORIGIN || "http://wpagf.dvl.to:8080";
const base = `${origin}/wp-admin/admin.php?page=wccs-checkoutsuite&section=checkouts`;
const runId = Date.now();
const profileName = `CHK-003 ${runId}`;
const sectionTitle = `CHK-003 seção ${runId}`;
const findings = [];
const failures = [];
const pageErrors = [];
const apiErrors = [];
let step = "initialising";
let profileWasPersisted = false;

const browser = await chromium.launch({
  executablePath: process.env.WCCS_CHROME || "/usr/bin/google-chrome",
  args: ["--no-sandbox"],
});
const context = await browser.newContext({
  viewport: { width: 1668, height: 1050 },
});
const page = await context.newPage();
page.setDefaultTimeout(10000);

page.on("pageerror", (error) => pageErrors.push(error.message));
page.on("response", (response) => {
  if (response.url().includes("/wp-json/") && response.status() >= 400) {
    apiErrors.push(
      `${response.status()} ${response.request().method()} ${response.url()}`,
    );
  }
});
page.on("requestfailed", (request) => {
  if (request.url().includes("/wp-json/")) {
    apiErrors.push(`failed ${request.method()} ${request.url()}`);
  }
});

async function addAuthentication() {
  if (process.env.WCCS_ADMIN_USER && process.env.WCCS_ADMIN_PASSWORD) {
    await page.goto(`${origin}/wp-login.php`, {
      waitUntil: "domcontentloaded",
      timeout: 30000,
    });
    await page.fill("#user_login", process.env.WCCS_ADMIN_USER);
    await page.fill("#user_pass", process.env.WCCS_ADMIN_PASSWORD);
    await page.click("#wp-submit");
    await page.waitForURL(/\/wp-admin\//, { timeout: 15000 });
    return;
  }

  for (const cookie of [
    process.env.WCCS_COOKIE,
    process.env.WCCS_AUTH_COOKIE,
  ].filter(Boolean)) {
    const split = cookie.indexOf("=");
    await context.addCookies([
      {
        name: cookie.slice(0, split),
        value: cookie.slice(split + 1),
        domain: new URL(origin).hostname,
        path: "/",
        httpOnly: true,
        secure: false,
        sameSite: "Lax",
      },
    ]);
  }
}

async function readDraft() {
  return page.evaluate(async () => {
    const boot = /** @type {any} */ (window).wccsAdmin ?? {};
    const rest = boot.rest ?? {};
    const response = await fetch(
      (rest.root ?? "") + (rest.namespace ?? "") + (rest.routes?.draft ?? ""),
      {
        credentials: "same-origin",
        headers: { "X-WP-Nonce": rest.nonce ?? "" },
      },
    );

    if (!response.ok) {
      return null;
    }

    const answer = await response.json();
    return answer?.document ?? answer;
  });
}

async function listedSections() {
  return page
    .locator(".wccs-container-list__items [aria-pressed]")
    .allInnerTexts();
}

async function selectedCheckoutName() {
  return page
    .locator('.wccs-checkouts__strip [role="tab"][aria-selected="true"]')
    .first()
    .innerText();
}

async function saveAndWait(description) {
  step = `save:${description}`;
  const saveButton = page.getByRole("button", {
    name: "Salvar alterações",
    exact: true,
  });
  await saveButton.waitFor();
  if (!(await saveButton.isEnabled())) {
    throw new Error(`Save button was disabled for ${description}.`);
  }
  const responsePromise = page.waitForResponse(
    (response) =>
      response.request().method() === "PUT" &&
      response.url().includes("/wp-json/"),
    { timeout: 30000 },
  );
  await saveButton.click();
  const response = await responsePromise;
  if (!response.ok()) {
    throw new Error(`Save failed for ${description}: ${response.status()}.`);
  }
  await page
    .getByText("Alterações salvas com sucesso.", { exact: true })
    .waitFor();
  findings.push(`saved:${description}`);
}

async function selectCheckout(name) {
  const tab = page.getByRole("tab", { name, exact: true });
  await tab.waitFor();
  await tab.click();
  await page.waitForFunction(
    (expected) =>
      Array.from(
        document.querySelectorAll(
          '.wccs-checkouts__strip [role="tab"][aria-selected="true"]',
        ),
      ).some((node) => node.textContent.includes(expected)),
    name,
  );
}

async function removeTemporaryProfile() {
  const tab = page.getByRole("tab", { name: profileName, exact: true });
  if (0 === (await tab.count())) {
    return;
  }

  await tab.click();
  await page
    .getByRole("button", { name: "Excluir checkout", exact: true })
    .click();
  const saveButton = page.getByRole("button", {
    name: "Salvar alterações",
    exact: true,
  });
  if (await saveButton.isEnabled()) {
    await saveAndWait("remove temporary checkout");
  }
}

try {
  await addAuthentication();

  step = "open-checkouts";
  await page.goto(base, {
    waitUntil: "domcontentloaded",
    timeout: 30000,
  });
  if (page.url().includes("/wp-login.php")) {
    throw new Error("Admin authentication was rejected.");
  }
  await page.locator("#editorView").waitFor();
  await page.locator(".wccs-checkouts__strip").waitFor();

  step = "visual-workspace";
  const workspaceLayout = await page.evaluate(() => {
    const workspace = document.querySelector(
      "#editorView.wccs-checkout-workspace",
    );
    const profileRail = workspace?.querySelector(".wccs-checkouts--sidebar");
    const editorGrid = workspace?.querySelector(".editor-grid");

    return {
      display: workspace ? getComputedStyle(workspace).display : "",
      profileColumn: profileRail
        ? getComputedStyle(profileRail).gridColumn
        : "",
      editorColumn: editorGrid ? getComputedStyle(editorGrid).gridColumn : "",
    };
  });
  if (
    workspaceLayout.display !== "grid" ||
    workspaceLayout.profileColumn !== "1" ||
    workspaceLayout.editorColumn !== "2"
  ) {
    throw new Error(
      `Checkout workspace columns are invalid: ${JSON.stringify(
        workspaceLayout,
      )}`,
    );
  }
  findings.push("visual:checkout rail and editor share the workspace grid");

  await page.setViewportSize({ width: 820, height: 1050 });
  const mobileColumns = await page.locator("#editorView").evaluate((node) => {
    return getComputedStyle(node).gridTemplateColumns;
  });
  if (mobileColumns.split(" ").length !== 1) {
    throw new Error(
      `Checkout workspace did not collapse on narrow viewport: ${mobileColumns}`,
    );
  }
  findings.push("visual:narrow workspace collapses to one column");
  await page.setViewportSize({ width: 1668, height: 1050 });

  step = "default-checkout";
  const initialName = await selectedCheckoutName();
  if (!initialName.includes("Checkout padrão")) {
    throw new Error(
      `Expected Checkout padrão to be active, got: ${initialName}.`,
    );
  }
  findings.push("default:Checkout padrão active");

  step = "create-alternate";
  await page
    .getByRole("button", { name: "Novo checkout", exact: true })
    .click();
  const createDialog = page.getByRole("dialog", { name: "Novo checkout" });
  await createDialog.locator("#wccs-profile-name").fill(profileName);
  await createDialog
    .locator('input[name="wccs-profile-source"]')
    .first()
    .check();
  await createDialog
    .getByRole("button", { name: "Criar checkout", exact: true })
    .click();
  await page.getByRole("tab", { name: profileName, exact: true }).waitFor();
  findings.push("alternate:created");

  step = "select-alternate";
  await selectCheckout(profileName);
  const alternateName = await selectedCheckoutName();
  if (!alternateName.includes(profileName)) {
    throw new Error(
      `Alternate checkout did not remain active: ${alternateName}.`,
    );
  }
  findings.push("alternate:selected");

  step = "create-owned-section";
  await page.getByRole("button", { name: "Nova seção", exact: true }).click();
  const sectionDialog = page.getByRole("dialog", { name: "Nova seção" });
  await sectionDialog.locator("#wccs-new-section-title").fill(sectionTitle);
  await sectionDialog
    .getByRole("button", { name: "Adicionar seção", exact: true })
    .click();
  await saveAndWait("alternate checkout section");
  profileWasPersisted = true;

  const storedAfterSave = await readDraft();
  const storedProfile = (storedAfterSave?.profiles ?? []).find(
    (profile) => profile.name === profileName,
  );
  const ownedTitles = (storedProfile?.sections ?? []).map(
    (section) => section.title,
  );
  if (!ownedTitles.includes(sectionTitle)) {
    throw new Error(`Saved section is not owned by ${profileName}.`);
  }
  findings.push("alternate:composition owns the created section");

  const alternateListed = await listedSections();
  if (!alternateListed.some((label) => label.includes(sectionTitle))) {
    throw new Error(
      "The alternate composition does not list its owned section.",
    );
  }
  findings.push("alternate:owned section rendered");

  step = "return-default";
  await selectCheckout("Checkout padrão");
  const defaultName = await selectedCheckoutName();
  const defaultListed = await listedSections();
  if (
    !defaultName.includes("Checkout padrão") ||
    defaultListed.some((label) => label.includes(sectionTitle))
  ) {
    throw new Error(
      "Returning to Checkout padrão retained the alternate section.",
    );
  }
  findings.push("default:section list restored without alternate section");

  step = "reload-default";
  await page.reload({ waitUntil: "domcontentloaded", timeout: 30000 });
  await page.locator(".wccs-checkouts__strip").waitFor();
  if (!(await selectedCheckoutName()).includes("Checkout padrão")) {
    throw new Error(
      "Reload did not restore Checkout padrão as the active checkout.",
    );
  }
  await selectCheckout(profileName);
  if (!(await listedSections()).some((label) => label.includes(sectionTitle))) {
    throw new Error("Reloaded alternate checkout lost its owned section.");
  }
  findings.push("reload:alternate composition and section restored");

  await selectCheckout("Checkout padrão");
  findings.push("return:Checkout padrão restored after reload");

  if (pageErrors.length > 0) {
    throw new Error(`Browser pageerror detected: ${pageErrors.join(" | ")}`);
  }
  if (apiErrors.length > 0) {
    throw new Error(`REST/API error detected: ${apiErrors.join(" | ")}`);
  }

  console.log(
    JSON.stringify(
      {
        ok: true,
        flow: [
          "section=checkouts",
          "Checkout padrão active",
          " alternate selected",
          " alternate composition section verified",
          " Checkout padrão restored",
        ],
        findings,
        pageErrors,
        apiErrors,
      },
      null,
      2,
    ),
  );
} catch (error) {
  failures.push(`step=${step} url=${page.url()}`);
  failures.push(String(error?.message || error).split("\n")[0]);
  console.log(
    JSON.stringify(
      {
        ok: false,
        findings,
        failures,
        pageErrors,
        apiErrors,
      },
      null,
      2,
    ),
  );
  process.exitCode = 1;
} finally {
  try {
    if (
      profileWasPersisted ||
      (await page
        .getByRole("tab", { name: profileName, exact: true })
        .count()) > 0
    ) {
      await removeTemporaryProfile();
    }
  } catch (cleanupError) {
    failures.push(
      `cleanup=${String(cleanupError?.message || cleanupError).split("\n")[0]}`,
    );
    process.exitCode = 1;
  }
  await browser.close();
}
