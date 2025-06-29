window.addEventListener('DOMContentLoaded', function() {

  // ─── Helper functions for rendering the upload log ───
  let rowMap = {};   // filename → <tr> element
  let doneCount = 0; // how many uploads have finished

  function makeRow(filename, status) {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${filename}</td>
      <td class="vidId"></td>
      <td class="statCell">
        <div class="bar"><div class="fill" style="width:0"></div></div>
        <span class="txt">${status}</span>
      </td>`;
    document.querySelector('.log-table tbody').appendChild(tr);
    rowMap[filename] = tr;
  }

  function updateBar(tr, pct, statusText) {
    const fill = tr.querySelector('.fill');
    const txt  = tr.querySelector('.txt');
    fill.style.width = pct + '%';
    txt.textContent = statusText;
  }


  // ─── Header/placeholder logic ───
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
    null,null,"untitled","Video Page Post Ad","", "",null,"LEARN_MORE","[]","No",null,"No","USER_ENROLLED_NON_DCO","TEXT_LIQUIDITY"
  ];

  const idxCampaignName = headers.indexOf("Campaign Name");
  const idxVideoID      = headers.indexOf("Video ID");
  const idxVideoFile    = headers.indexOf("Video File Name");
  const idxAdName       = headers.indexOf("Ad Name");
  const idxAdSetName    = headers.indexOf("Ad Set Name");
  const idxBody         = headers.indexOf("Body");
  const idxTitle        = headers.indexOf("Title");
  const idxLink         = headers.indexOf("Link");

  function stripExt(name) {
    return name.replace(/\.[^.]+$/, '');
  }

  // Prefill form fields
  document.getElementById('bodyField').value         = placeholderRow[idxBody] || "";
  document.getElementById('titleField').value        = placeholderRow[idxTitle] || "";
  document.getElementById('linkField').value         = placeholderRow[idxLink] || "";
  document.getElementById('campaignNameField').value = placeholderRow[idxCampaignName] || "";

  // ─── Structure picker ───
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
          <label for="customABO">Custom:
            <input type="number" min="1" max="${videoCount||10}"
                   id="adsetNumInput" class="adset-num-input" value="2">
            ad sets (max ${videoCount||10})
          </label>
        </div>
      </div>`;
    document.querySelectorAll('input[name="structure"]').forEach(radio => {
      radio.addEventListener('change', function() {
        document.getElementById('adsetNumInput').disabled = (this.value !== "custom");
      });
    });
    document.getElementById('adsetNumInput').addEventListener('input', function() {
      let val = Math.max(1, Math.min(videoCount||10, parseInt(this.value) || 1));
      this.value = val;
    });
    document.getElementById('adsetNumInput').disabled = true;
  }
  renderStructurePicker(10);

  // ─── Preview population ───
  let globalData = [];
  let rows = [ placeholderRow.slice() ];
  function populatePreview() {
    const cont = document.getElementById('previewContainer');
    cont.innerHTML = '';
    const c = document.createElement('div');
    c.className = 'form-container';

    [idxVideoID, idxVideoFile].forEach(i => {
      const lab = document.createElement('label');
      lab.textContent = headers[i];
      const inp = document.createElement('input');
      inp.readOnly = true;
      inp.value = rows[0][i] || '';
      c.appendChild(lab);
      c.appendChild(inp);
    });

    headers.forEach((h,i) => {
      if (i===idxVideoID||i===idxVideoFile) return;
      const lab = document.createElement('label');
      lab.textContent = h;
      const inp = document.createElement('input');
      inp.readOnly = true;
      inp.value = rows[0][i] || '';
      c.appendChild(lab);
      c.appendChild(inp);
    });

    cont.appendChild(c);
  }
  populatePreview();

  // ─── Load videos button ───
  document.getElementById('loadBtn').addEventListener('click', async () => {
    try {
      const r = await fetch('latest_fb_ids.json?t='+Date.now());
      if (!r.ok) throw new Error('latest_fb_ids.json not found');
      const data = await r.json();
      if (!Array.isArray(data) || data.length<1) throw new Error('JSON must be a non-empty array');
      globalData = data;
      renderStructurePicker(data.length);
      rows = [];
      const campaignNameVal = document.getElementById('campaignNameField').value.trim();
      data.forEach(({filename, video_id}) => {
        const nr = placeholderRow.slice();
        nr[idxVideoID]   = video_id;
        nr[idxVideoFile] = filename;
        if (idxCampaignName>=0 && campaignNameVal) nr[idxCampaignName] = campaignNameVal;
        if (idxAdName>=0) nr[idxAdName] = stripExt(filename);
        rows.push(nr);
      });
      populatePreview();
      alert(`Loaded ${rows.length} video${rows.length>1?'s':''}!`);
    } catch(err) {
      alert(err.message);
    }
  });

  // ─── Download Excel button ───
  document.getElementById('downloadBtn').addEventListener('click', () => {
    if (!globalData.length) {
      alert("Load video IDs first.");
      return;
    }
    // your existing XLSX build/write code here
  });

  // ─── Toggle token visibility ───
  document.getElementById('toggleToken').addEventListener('click', () => {
    const input = document.getElementById('accessToken');
    const eye   = document.getElementById('eyeIcon');
    if (input.type === "password") {
      input.type = "text";
      eye.textContent = "🙈";
    } else {
      input.type = "password";
      eye.textContent = "👁️";
    }
  });

  // ─── Uploader Logic + Progress UI ───
  document.getElementById('uploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const folderId     = document.getElementById('folderId').value.trim();
    const accessToken  = document.getElementById('accessToken').value.trim();
    const accountId    = document.getElementById('accountId').value.trim();
    const googleApiKey = document.getElementById('googleApiKey').value.trim();
    const uploadBtn    = document.getElementById('uploadBtn');
    const uploadLogDiv = document.getElementById('uploadLogContainer');

    uploadBtn.disabled = true;
    uploadLogDiv.innerHTML = '';

    // 1) Count files BEFORE uploading
    let fileCount = 0;
    try {
      const respCount = await fetch('upload.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          folderId, accessToken, accountId, googleApiKey, count: true
        })
      });
      const info = await respCount.json();
      fileCount = info.count || 0;
    } catch (err) {
      fileCount = 0;
    }

    // 2) Build the log table skeleton
    uploadLogDiv.innerHTML = '<b>Upload Log:</b>';
    const table = document.createElement('table');
    table.className = 'log-table';
    const thead = document.createElement('thead');
    const trh = document.createElement('tr');
    ['Filename','Video ID','Status'].forEach(h => {
      const th = document.createElement('th');
      th.textContent = h;
      trh.appendChild(th);
    });
    thead.appendChild(trh);
    table.appendChild(thead);
    const tbody = document.createElement('tbody');
    table.appendChild(tbody);
    uploadLogDiv.appendChild(table);

    // 3) Kick off job and get jobId
    let jobId;
    try {
      const respJob = await fetch('upload.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ folderId, accessToken, accountId, googleApiKey })
      });
      const j = await respJob.json();
      jobId = j.jobId;
    } catch (err) {
      uploadLogDiv.textContent = 'Error starting upload: ' + err.message;
      uploadBtn.disabled = false;
      return;
    }

    // 4) Listen to SSE for progress
    let lastHeartbeat = Date.now();
    function heartbeatUI() {
      const ago = Math.round((Date.now() - lastHeartbeat)/1000);
      uploadLogDiv.querySelector('table').insertAdjacentHTML('afterend',
        `<div class="heartbeat">Last activity: ${ago}s ago</div>`
      );
    }
    setInterval(heartbeatUI, 1000);

    const es = new EventSource(`progress.php?jobId=${jobId}`);
    es.onmessage = e => {
      lastHeartbeat = Date.now();
      const msg = JSON.parse(e.data);
      if (msg.init) {
        msg.files.forEach(name => makeRow(name, 'Queued'));
        return;
      }
      if (msg.phase==='download' || msg.phase==='upload') {
        const pct = msg.pct;
        const verb = (msg.phase==='download') ? 'Downloading' : 'Uploading';
        updateBar(rowMap[msg.filename], pct, `${verb} – ${pct}%`);
        return;
      }
      if (msg.phase==='done') {
        const ok = msg.status==='success';
        updateBar(rowMap[msg.filename], 100, ok?'Uploaded ✅':'Failed ❌');
        doneCount++;
      }
    };
    es.onerror = () => {
      es.close();
      uploadLogDiv.insertAdjacentHTML('beforeend',
        '<div class="error">Connection lost.</div>'
      );
      uploadBtn.disabled = false;
    };

  }); // end submit listener

}); // end DOMContentLoaded
