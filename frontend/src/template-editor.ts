type TemplateVersion = { id: string; version: number; definition_count: number };
type OwnedTemplate = { id: string; name: string; versions: TemplateVersion[] };
type OwnedAsset = { id: string; mime_type: string; byte_size: number; width: number; height: number; created_at: string };
type ApiClient = (path: string, init?: RequestInit) => Promise<any>;

type DefinitionEditor = {
  fieldset: HTMLFieldSetElement;
  legend: HTMLLegendElement;
  displayName: HTMLInputElement;
  quantity: HTMLInputElement;
  frontAsset: HTMLSelectElement;
  backAsset: HTMLSelectElement;
  moveUp: HTMLButtonElement;
  moveDown: HTMLButtonElement;
  remove: HTMLButtonElement;
};

function makeButton(label: string, onClick: () => void, secondary = false): HTMLButtonElement {
  const control = document.createElement("button");
  control.type = "button";
  control.textContent = label;
  if (secondary) control.className = "secondary";
  control.addEventListener("click", onClick);
  return control;
}

function makeLabel(text: string, control: HTMLElement): HTMLLabelElement {
  const label = document.createElement("label");
  label.append(document.createTextNode(text), control);
  return label;
}

export function renderTemplateEditor(
  initialTemplates: OwnedTemplate[],
  initialAssets: OwnedAsset[],
  api: ApiClient,
  onCatalogChanged: (templates: OwnedTemplate[]) => void,
): HTMLElement {
  let templates = initialTemplates;
  let assets = initialAssets;
  const assetSelects = new Set<HTMLSelectElement>();
  const assetPreviews = new Map<HTMLSelectElement, HTMLImageElement>();
  const definitions: DefinitionEditor[] = [];

  const section = document.createElement("section");
  section.className = "panel template-manager";
  const title = document.createElement("h2");
  title.textContent = "Your deck templates";
  const introduction = document.createElement("p");
  introduction.className = "muted";
  introduction.textContent = "Build reusable decks from card images you own. Saved versions stay unchanged in tables that already use them.";
  const createTemplate = makeButton("Create deck template", () => openEditor(null), true);
  const list = document.createElement("div");
  list.className = "template-list";
  const editor = document.createElement("div");
  editor.className = "template-editor";
  editor.hidden = true;
  const feedback = document.createElement("p");
  feedback.className = "template-feedback muted";
  feedback.setAttribute("role", "status");
  feedback.setAttribute("aria-live", "polite");
  section.append(title, introduction, createTemplate, feedback, editor, list);

  function fillAssetSelect(select: HTMLSelectElement, allowNone: boolean): void {
    const previous = select.value;
    select.replaceChildren();
    if (allowNone) {
      const none = document.createElement("option");
      none.value = "";
      none.textContent = "No image";
      select.append(none);
    } else {
      const choose = document.createElement("option");
      choose.value = "";
      choose.textContent = "Choose an owned image";
      select.append(choose);
    }
    for (const asset of assets) {
      const option = document.createElement("option");
      option.value = asset.id;
      option.textContent = `${asset.mime_type.replace("image/", "").toUpperCase()} · ${asset.width} × ${asset.height} · ${Math.ceil(asset.byte_size / 1024)} KB`;
      select.append(option);
    }
    if ([...select.options].some((option) => option.value === previous)) select.value = previous;
    updatePreview(select);
  }

  function updatePreview(select: HTMLSelectElement): void {
    const preview = assetPreviews.get(select);
    if (!preview) return;
    if (!select.value) {
      preview.hidden = true;
      preview.removeAttribute("src");
      return;
    }
    preview.hidden = false;
    preview.src = `/protected-assets/${encodeURIComponent(select.value)}`;
  }

  async function refreshAssets(): Promise<void> {
    const response = await api("/api/assets");
    assets = (response.assets ?? []) as OwnedAsset[];
    for (const select of assetSelects) {
      const allowNone = select.dataset.allowNone === "true";
      fillAssetSelect(select, allowNone);
    }
  }

  async function refreshCatalog(): Promise<void> {
    const [templateResponse, assetResponse] = await Promise.all([api("/api/templates"), api("/api/assets")]);
    templates = (templateResponse.templates ?? []) as OwnedTemplate[];
    assets = (assetResponse.assets ?? []) as OwnedAsset[];
    onCatalogChanged(templates);
    renderTemplateList();
  }

  function assetPicker(labelText: string, allowNone: boolean): { wrapper: HTMLElement; select: HTMLSelectElement } {
    const wrapper = document.createElement("div");
    wrapper.className = "asset-picker";
    const select = document.createElement("select");
    select.dataset.allowNone = String(allowNone);
    select.setAttribute("aria-label", labelText);
    fillAssetSelect(select, allowNone);
    assetSelects.add(select);
    const preview = document.createElement("img");
    preview.className = "asset-preview";
    preview.alt = `${labelText} preview`;
    preview.hidden = true;
    assetPreviews.set(select, preview);
    select.addEventListener("change", () => updatePreview(select));
    const file = document.createElement("input");
    file.type = "file";
    file.accept = "image/jpeg,image/png,image/webp";
    file.setAttribute("aria-label", `Upload ${labelText.toLowerCase()}`);
    file.addEventListener("change", async () => {
      const chosen = file.files?.[0];
      if (!chosen) return;
      file.disabled = true;
      feedback.textContent = `Uploading ${chosen.name}…`;
      const form = new FormData();
      form.append("asset", chosen);
      try {
        const result = await api("/api/assets", { method: "POST", body: form });
        await refreshAssets();
        select.value = String(result.asset.id);
        updatePreview(select);
        feedback.textContent = "Image uploaded to your asset library.";
      } catch (error) {
        feedback.textContent = (error as Error).message;
      } finally {
        file.disabled = false;
        file.value = "";
      }
    });
    wrapper.append(makeLabel(labelText, select), makeLabel("Upload new image", file), preview);
    return { wrapper, select };
  }

  function reindexDefinitions(): void {
    definitions.forEach((definition, index) => {
      definition.legend.textContent = `Card ${index + 1}`;
      definition.moveUp.textContent = `Move card ${index + 1} up`;
      definition.moveDown.textContent = `Move card ${index + 1} down`;
      definition.moveUp.disabled = index === 0;
      definition.moveDown.disabled = index === definitions.length - 1;
      definition.remove.textContent = `Remove card ${index + 1}`;
      definition.remove.disabled = definitions.length <= 1;
    });
  }

  function moveDefinition(fieldset: HTMLFieldSetElement, offset: -1 | 1): void {
    const index = definitions.findIndex((definition) => definition.fieldset === fieldset);
    const next = index + offset;
    if (index < 0 || next < 0 || next >= definitions.length) return;
    const definition = definitions[index];
    if (!definition) return;
    definitions.splice(index, 1);
    definitions.splice(next, 0, definition);
    const panel = editor.querySelector(".template-card-definitions");
    for (const item of definitions) panel?.append(item.fieldset);
    reindexDefinitions();
    (offset < 0 ? definition.moveUp : definition.moveDown).focus();
  }

  function addDefinition(): void {
    const fieldset = document.createElement("fieldset");
    fieldset.className = "template-card-definition";
    const legend = document.createElement("legend");
    fieldset.append(legend);
    const displayName = document.createElement("input");
    displayName.type = "text";
    displayName.maxLength = 160;
    displayName.autocomplete = "off";
    const quantity = document.createElement("input");
    quantity.type = "number";
    quantity.min = "1";
    quantity.max = "10000";
    quantity.value = "1";
    const front = assetPicker("Front image", false);
    const back = assetPicker("Card back override", true);
    const moveUp = makeButton("Move card up", () => moveDefinition(fieldset, -1), true);
    const moveDown = makeButton("Move card down", () => moveDefinition(fieldset, 1), true);
    const remove = makeButton("Remove card", () => {
      const index = definitions.findIndex((item) => item.fieldset === fieldset);
      if (index >= 0) definitions.splice(index, 1);
      for (const select of [front.select, back.select]) {
        assetSelects.delete(select);
        assetPreviews.delete(select);
      }
      fieldset.remove();
      reindexDefinitions();
    }, true);
    const definition: DefinitionEditor = { fieldset, legend, displayName, quantity, frontAsset: front.select, backAsset: back.select, moveUp, moveDown, remove };
    definitions.push(definition);
    fieldset.append(makeLabel("Card label (optional)", displayName), makeLabel("Copies", quantity), front.wrapper, back.wrapper, moveUp, moveDown, remove);
    editor.querySelector(".template-card-definitions")?.append(fieldset);
    reindexDefinitions();
  }

  function openEditor(template: OwnedTemplate | null): void {
    definitions.splice(0, definitions.length);
    assetSelects.clear();
    assetPreviews.clear();
    editor.replaceChildren();
    const heading = document.createElement("h3");
    heading.textContent = template ? `Add a version to ${template.name}` : "Create a deck template";
    const explanation = document.createElement("p");
    explanation.className = "muted";
    explanation.textContent = "Each save creates a new immutable version. Include the full ordered deck in this version.";
    const name = document.createElement("input");
    name.type = "text";
    name.maxLength = 160;
    name.autocomplete = "off";
    name.value = template?.name ?? "";
    name.disabled = template !== null;
    const nameLabel = makeLabel("Template name", name);
    const defaultBack = assetPicker("Default card back (optional)", true);
    const definitionsPanel = document.createElement("div");
    definitionsPanel.className = "template-card-definitions";
    const definitionsHeading = document.createElement("h4");
    definitionsHeading.textContent = "Ordered card definitions";
    const addCard = makeButton("Add card", addDefinition, true);
    const error = document.createElement("p");
    error.className = "template-feedback";
    error.setAttribute("role", "alert");
    const save = makeButton(template ? "Save new version" : "Create template", () => void saveTemplate());
    const cancel = makeButton("Cancel", () => { editor.hidden = true; feedback.textContent = ""; }, true);
    const formActions = document.createElement("div");
    formActions.className = "template-form-actions";
    formActions.append(save, cancel);
    editor.append(heading, explanation, nameLabel, defaultBack.wrapper, definitionsHeading, definitionsPanel, addCard, error, formActions);

    async function saveTemplate(): Promise<void> {
      const templateName = template?.name ?? name.value.trim();
      if (!templateName) { error.textContent = "Enter a name for this deck template."; name.focus(); return; }
      if (definitions.length < 1) { error.textContent = "Add at least one card definition."; addCard.focus(); return; }
      const rows = definitions.map((definition) => ({
        display_name: definition.displayName.value.trim() || null,
        quantity: Number(definition.quantity.value),
        front_asset_id: definition.frontAsset.value,
        back_asset_id: definition.backAsset.value || null,
      }));
      const invalid = rows.find((row) => !row.front_asset_id || !Number.isInteger(row.quantity) || row.quantity < 1 || row.quantity > 10000);
      if (invalid) { error.textContent = "Choose an owned front image and a quantity from 1 to 10000 for every card."; return; }
      save.disabled = true;
      error.textContent = "Saving immutable template version…";
      try {
        const payload = { definitions: rows, default_back_asset_id: defaultBack.select.value || null };
        if (template) {
          await api(`/api/templates/${encodeURIComponent(template.id)}/versions`, { method: "POST", body: JSON.stringify(payload) });
        } else {
          await api("/api/templates", { method: "POST", body: JSON.stringify({ name: templateName, ...payload }) });
        }
        await refreshCatalog();
        editor.hidden = true;
        feedback.textContent = `Saved ${template ? `a new version of ${template.name}` : templateName}. It is available in table setup.`;
      } catch (caught) {
        error.textContent = (caught as Error).message;
      } finally {
        save.disabled = false;
      }
    }

    editor.hidden = false;
    feedback.textContent = "";
    addDefinition();
    if (template) addCard.focus(); else name.focus();
    editor.scrollIntoView({ block: "nearest" });
  }

  function renderTemplateList(): void {
    list.replaceChildren();
    if (templates.length === 0) {
      const empty = document.createElement("p");
      empty.className = "muted";
      empty.textContent = "No deck templates yet. Create one to use your own card artwork in a table.";
      list.append(empty);
      return;
    }
    for (const template of templates) {
      const item = document.createElement("article");
      item.className = "template-row";
      const heading = document.createElement("h3");
      heading.textContent = template.name;
      const versions = document.createElement("ul");
      for (const version of template.versions ?? []) {
        const row = document.createElement("li");
        row.textContent = `Version ${version.version} · ${version.definition_count} card definitions`;
        versions.append(row);
      }
      item.append(heading, versions, makeButton(`Add version to ${template.name}`, () => openEditor(template), true));
      list.append(item);
    }
  }

  renderTemplateList();
  return section;
}
