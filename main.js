window.addEventListener('DOMContentLoaded', function() {

  const headers = [
    "Campaign ID","Campaign Name","Campaign Status","Campaign Objective","Buying Type",
    "Campaign Start Time","New Objective","Buy With Prime Type","Is Budget Scheduling Enabled For Campaign",
    "Campaign High Demand Periods","Buy With Integration Partner","Ad Set ID","Ad Set Run Status",
    "Ad Set Lifetime Impressions","Ad Set Name","Ad Set Time Start","Ad Set Daily Budget",
    "Destination Type","Ad Set Lifetime Budget","Use Accelerated Delivery",
    "Is Budget Scheduling Enabled For Ad Set","Ad Set High Demand Periods","Link Object ID",
    "Optimized Conversion Tracking Pixels","Optimized Event","Link","Countries","Location Types",
    "Age Min","Age Max","Brand Safety Inventory Filtering Levels","Optimization Goal",
    "Attribution Spec","Billing Event","Ad Set Bid Strategy","Ad ID","Ad Status","Preview Link",
    "Instagram Preview Link","Ad Name","Title","Body","Optimize text per person",
    "Conversion Tracking Pixels","Image Hash","Image File Name","Creative Type","Video ID",
    "Video File Name","Instagram Account ID","Call to Action","Additional Custom Tracking Specs",
    "Video Retargeting","Permalink","Use Page as Actor","Degrees of Freedom Type","Text Transformations"
  ];
  const placeholderRow = [
    null,"Campaign #1","PAUSED","Outcome Leads","AUCTION","06/17/2025 1:09:54 pm","Yes","NONE",
    "No","[]","NONE",null,"ACTIVE",0,"Adset #1",
    "06/17/2025 1:09:54 pm",15,"UNDEFINED",0,"No","No","[]",null,null,"LEAD",
    "https://putyourlinkhere.com",
    "US","home, recent",18,40,
    "FACEBOOK_RELAXED, AN_RELAXED","OFFSITE_CONVERSIONS",
    '[{"event_type":"CLICK_THROUGH","window_days":7},{"event_type":"VIEW_THROUGH","window_days":1},{"event_type":"ENGAGED_VIDEO_VIEW","window_days":1}]',
    "IMPRESSIONS","Highest volume or value",null,"ACTIVE",null,null,
    "Ad Name #1","Headline","Ad Copy","Yes",
    null,null,"untitled","Video Page Post Ad", "", "", null,"LEARN_MORE","[]","No",null,"No","USER_ENROLLED_NON_DCO","TEXT_LIQUIDITY"
  ];

  const idxCampaignName = headers.indexOf("Campaign Name");
  const idxVideoID    = headers.indexOf("Video ID");
  const idxVideoFile  = headers.indexOf("Video File Name");
  const googleApiKey = document.getElementById('googleApiKey').value.trim();
  const idxAdName     = headers.indexOf("Ad Name");
  const idxAdSetName  = headers.indexOf("Ad Set Name");
  const idxBody       = headers.indexOf("Body");
  const idxTitle      = headers.indexOf("Title");
  const idxLink       = headers.indexOf("Link");

  // Prefill Ad Identify
  document.getElementById('bodyField').value = placeholderRow[idxBody] || "";
  document.getElementById('titleField').value = placeholderRow[idxTitle] || "";
  document.getElementById('linkField').value = placeholderRow[idxLink] || "";
  document.getElementById('campaignNameField').value = placeholderRow[idxCampaignName] || "";

  // Structure Picker is always visible:
  function renderStructurePicker(videoCount) {
    const el = document.getElementById('structurePicker');
    el.style.display = "";
    el.innerHTML = `
      <div class="structure-picker">
        <div class="structure-title">Campaign Structure</div>
        <div class="structure-choice">
          <input type="radio" name="structure" id="oneAdset" value="one-adset" checked>
          <label for="oneAdset">1 Ad Set, Multiple Ads (All videos in <b>Adset #1</b>)</label>
        </div>
        <div class="structure-choice">
          <input type="radio" name="structure" id="abo1to1" value="abo-1to1">
          <label for="abo1to1">1 Ad Set per 1 Ad (Advanced ABO 1:1)</label>
        </div>
        <div class="structure-choice">
          <input type="radio" name="structure" id="customABO" value="custom">
          <label for="customABO">Custom: <span>Divide creatives into </span>
            <input type="number" min="1" max="${videoCount||10}" id="adsetNumInput" class="adset-num-input" value="2"> ad sets (max: ${videoCount||10})
          </label>
        </div>
      </div>
    `;
    document.querySelectorAll('input[name="structure"]').forEach(radio => {
      radio.addEventListener('change', function() {
        document.getElementById('adsetNumInput').disabled = (this.value !== "custom");
      });
    });
    document.getElementById('adsetNumInput').addEventListener('input', function() {
      let val = Math.max(1, Math.min((videoCount||10), parseInt(this.value) || 1));
      this.value = val;
    });
    document.getElementById('adsetNumInput').disabled = true;
  }
  renderStructurePicker(10);

  let globalData = [];
  let rows = [placeholderRow.map(x => x)];
  function stripExt(name){ return name.replace(/\.[^.]+$/,''); }
  function populatePreview(){
    const cont = document.getElementById('previewContainer');
    cont.innerHTML = '';
    const c = document.createElement('div');
    c.className = 'form-container';
    [idxVideoID, idxVideoFile].forEach(i => {
      const lab = document.createElement('label'); lab.textContent = headers[i];
      const inp = document.createElement('input'); inp.readOnly = true; inp.value = rows[0][i] ?? "";
      c.appendChild(lab); c.appendChild(inp);
    });
    headers.forEach((h, i) => {
      if (i === idxVideoID || i === idxVideoFile) return;
      const lab = document.createElement('label'); lab.textContent = h;
      const inp = document.createElement('input'); inp.readOnly = true; inp.value = rows[0][i] ?? "";
      c.appendChild(lab); c.appendChild(inp);
    });
    cont.appendChild(c);
  }
  populatePreview();

  document.getElementById('loadBtn').addEventListener('click', async ()=>{
    try{
      const r = await fetch('latest_fb_ids.json?t='+Date.now());
      if(!r.ok) throw new Error('latest_fb_ids.json not found');
      const data = await r.json();
      if(!Array.isArray(data) || data.length === 0) throw new Error('JSON must be an array of objects');
      globalData = data;
      renderStructurePicker(data.length);
      rows = [];
      const campaignNameVal = document.getElementById('campaignNameField').value.trim();
      data.forEach(({filename, video_id}) => {
        const newRow = placeholderRow.map(x => x);
        newRow[idxVideoID]   = video_id;
        newRow[idxVideoFile] = filename;
        if (idxCampaignName !== -1 && campaignNameVal) newRow[idxCampaignName] = campaignNameVal;
        const filenameBase = stripExt(filename);
        if (idxAdName !== -1) newRow[idxAdName] = filenameBase;
        rows.push(newRow);
      });
      populatePreview();
      alert('Loaded '+rows.length+' video'+(rows.length>1?'s':'')+'!');
    }catch(e){ alert(e.message); }
  });

  document.getElementById('downloadBtn').addEventListener('click', ()=>{
    if (!globalData.length) {
      alert("Load video IDs first.");
      return;
    }
    const bodyVal          = document.getElementById('bodyField').value.trim();
    const titleVal         = document.getElementById('titleField').value.trim();
    const linkVal          = document.getElementById('linkField').value.trim();
    const campaignNameVal  = document.getElementById('campaignNameField').value.trim();
    const baseBody         = placeholderRow[idxBody] || "";
    const baseTitle        = placeholderRow[idxTitle] || "";
    const baseLink         = placeholderRow[idxLink] || "";
    const structureRadios  = document.querySelectorAll('input[name="structure"]');
    let selected           = "one-adset";
    structureRadios.forEach(r => { if(r.checked) selected = r.value; });
    let resultRows = [];
    if (selected === "one-adset") {
      globalData.forEach(({filename, video_id}) => {
        const newRow = placeholderRow.map(x => x);
        newRow[idxAdSetName] = "Adset #1";
        if (idxCampaignName !== -1 && campaignNameVal) newRow[idxCampaignName] = campaignNameVal;
        newRow[idxAdName]    = stripExt(filename);
        newRow[idxVideoID]   = video_id;
        newRow[idxVideoFile] = filename;
        if (bodyVal    !== baseBody)  newRow[idxBody]  = bodyVal;
        if (titleVal   !== baseTitle) newRow[idxTitle] = titleVal;
        if (linkVal    !== baseLink)  newRow[idxLink]  = linkVal;
        resultRows.push(newRow);
      });
    } else if (selected === "abo-1to1") {
      globalData.forEach(({filename, video_id}) => {
        const newRow = placeholderRow.map(x => x);
        const name = stripExt(filename);
        newRow[idxAdSetName] = name;
        if (idxCampaignName !== -1 && campaignNameVal) newRow[idxCampaignName] = campaignNameVal;
        newRow[idxAdName]    = name;
        newRow[idxVideoID]   = video_id;
        newRow[idxVideoFile] = filename;
        if (bodyVal    !== baseBody)  newRow[idxBody]  = bodyVal;
        if (titleVal   !== baseTitle) newRow[idxTitle] = titleVal;
        if (linkVal    !== baseLink)  newRow[idxLink]  = linkVal;
        resultRows.push(newRow);
      });
    } else if (selected === "custom") {
      let N = Math.max(1, Math.min(globalData.length, parseInt(document.getElementById('adsetNumInput').value) || 1));
      let adsets = [];
      for (let i=0; i<N; ++i) adsets.push([]);
      globalData.forEach((obj, idx) => { adsets[idx % N].push(obj); });
      adsets.forEach((ads, i) => {
        ads.forEach(({filename, video_id}) => {
          const newRow = placeholderRow.map(x => x);
          newRow[idxAdSetName] = `Adset #${i+1}`;
          if (idxCampaignName !== -1 && campaignNameVal) newRow[idxCampaignName] = campaignNameVal;
          newRow[idxAdName]    = stripExt(filename);
          newRow[idxVideoID]   = video_id;
          newRow[idxVideoFile] = filename;
          if (bodyVal    !== baseBody)  newRow[idxBody]  = bodyVal;
          if (titleVal   !== baseTitle) newRow[idxTitle] = titleVal;
          if (linkVal    !== baseLink)  newRow[idxLink]  = linkVal;
          resultRows.push(newRow);
        });
      });
    }
    rows = resultRows;
    populatePreview();
    const ws = XLSX.utils.aoa_to_sheet([headers,...rows]);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Sheet1');
    const outName = campaignNameVal ? `${campaignNameVal}.xlsx` : 'facebook_campaign.xlsx';
    XLSX.writeFile(wb, outName);
  });

  // Toggle access token field
  document.getElementById('toggleToken').addEventListener('click', ()=>{
    let input = document.getElementById('accessToken');
    let eye = document.getElementById('eyeIcon');
    if (input.type === "password") { input.type = "text"; eye.textContent = "🙈"; }
    else { input.type = "password"; eye.textContent = "👁️"; }
  });

  // --- Uploader Logic + Log Table + Heartbeat ---
  document.getElementById('uploadForm').addEventListener('submit', async function(e){
    e.preventDefault();
    const folderId   = document.getElementById('folderId').value.trim();
    const googleApiKey  = document.getElementById('googleApiKey').value.trim();
    const accessToken= document.getElementById('accessToken').value.trim();
    const accountId  = document.getElementById('accountId').value.trim();
    const uploadBtn  = document.getElementById('uploadBtn');
    const uploadLogDiv = document.getElementById('uploadLogContainer');
    uploadLogDiv.innerHTML = "";
    uploadBtn.disabled = true;

    // Count files BEFORE uploading
    let fileCount = 0;
    try {
      const resp = await fetch('upload.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({folderId, accessToken, googleApiKey, accountId, count: true})
      });
      const info = await resp.json();
      fileCount = info.count || 0;
    } catch { fileCount = 0; }

    // Setup log table
    const table = document.createElement('table');
    table.className = "log-table";
    const thead = document.createElement('thead');
    const trh = document.createElement('tr');
    ["Filename", "Video ID", "Status"].forEach(txt => { const th = document.createElement('th'); th.textContent = txt; trh.appendChild(th); });
    thead.appendChild(trh); table.appendChild(thead);
    const tbody = document.createElement('tbody');
    table.appendChild(tbody);
    uploadLogDiv.innerHTML = "<b>Upload Log:</b>";
    uploadLogDiv.appendChild(table);
    let progressMsg = document.createElement('div');
    uploadLogDiv.insertBefore(progressMsg, table);

    // Heartbeat tracker
    let lastHeartbeatTime = Date.now();
    let doneCount = 0;
    function updateHeartbeatDisplay() {
      let ago = Math.round((Date.now() - lastHeartbeatTime) / 1000);
      progressMsg.innerHTML = `<span class="spinner"></span>Uploading... (${doneCount}/${fileCount || "?"} done) <span style="color:#888;font-size:.95em;">| Last server activity: ${ago}s ago</span>`;
      if (ago > 15) {
        progressMsg.innerHTML += `<br><span style="color:#c00;">No response from server for ${ago}s – backend may be stuck.</span>`;
      }
    }
    let heartbeatIntervalId = setInterval(updateHeartbeatDisplay, 1000);

    try {
      const r = await fetch('upload.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          folderId,
          accessToken,
          accountId,
          googleApiKey,
          stream: true
        })
      });
      if (!r.body) throw new Error("No response body (streaming not supported?)");
      const reader = r.body.getReader();
      let buf = "";
      let tokenError = false;
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buf += new TextDecoder().decode(value);
        let lines = buf.split("\n");
        buf = lines.pop();
        for (const line of lines) {
          if (!line.trim()) continue;
          let item;
          try { item = JSON.parse(line); } catch { continue; }
          if (item.heartbeat) {
            lastHeartbeatTime = Date.now();
            continue;
          }
          if (item.error && (item.error === 'fb_list_fail' || (item.detail && /access token/i.test(item.detail)))) {
            tokenError = true;
            break;
          }
          if (!item.filename) continue;
          const tr = document.createElement('tr');
          const td1 = document.createElement('td'); td1.textContent = item.filename ?? '';
          const td2 = document.createElement('td'); td2.textContent = item.video_id ?? '';
          const td3 = document.createElement('td'); 
          let status = item.status || (
            item.skipped ? "Skipped" : 
            (item.error ? "Failed" : "Uploaded")
          );
          td3.textContent = status;
          // Only log errors in console, not in UI
          if (status === "Failed" && item.error) {
            console.warn(`Upload failed for: ${item.filename}\nReason: ${item.error}\n`, item);
          }
          tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3);
          tbody.appendChild(tr);
          table.scrollIntoView(false);
          doneCount++;
          updateHeartbeatDisplay();
        }
        if (tokenError) break;
      }
      clearInterval(heartbeatIntervalId);
      if (tokenError) {
        document.getElementById('tokenModal').style.display = "flex";
        progressMsg.innerHTML = `<span style="color:#c00;font-weight:600">Renew Token</span>`;
      } else {
        progressMsg.innerHTML = `<b>Upload complete. (${doneCount}/${fileCount || "?"} files)</b>`;
      }
      uploadBtn.disabled = false;
    } catch (err) {
      clearInterval(heartbeatIntervalId);
      progressMsg.textContent = 'Error: ' + err.message;
      uploadBtn.disabled = false;
    }
  });

});
