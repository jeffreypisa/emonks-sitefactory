// Validate customer fields before the existing busy/submission handler runs.
(function(){
 document.querySelectorAll('form[data-customer-form]').forEach(form=>{
  const names=['customer_name','customer_username','customer_email','customer_confirmed'];
  const mode=form.querySelector('[data-customer-mode]');
  function sync(){if(!mode)return;const group=form.querySelector('[data-customer-fields]');group.hidden=mode.value==='none';group.disabled=group.hidden;}
  if(mode){mode.addEventListener('change',sync);sync();}
  function validate(name){if(mode?.value==='none')return true;const field=form.elements.namedItem(name);if(name==='customer_username')field.value=field.value.toLowerCase();const value=field.value.trim();let error='';
   if(name==='customer_name'&&(!value||value.length>100||/[<>\r\n]/.test(value)))error='Vul een naam zonder HTML of regeleinden in (maximaal 100 tekens).';
   if(name==='customer_username'&&(!value||value.length>60||!/^[a-zA-Z0-9 ._@-]+$/.test(value)))error='Gebruik maximaal 60 tekens: letters zonder accenten, cijfers, spaties of . - _ @.';
   if(name==='customer_email'&&(!value||field.validity.typeMismatch||!/^\S+@[^\s@]+\.[^\s@]+$/.test(value)))error='Vul een geldig e-mailadres in, bijvoorbeeld naam@bedrijf.nl.';
   if(name==='customer_confirmed'&&!field.checked)error='Bevestig dat je de klantgegevens en het e-mailadres hebt gecontroleerd.';
   const note=document.getElementById('emsf-'+name+'-error');note.textContent=error;note.hidden=!error;field.setAttribute('aria-invalid',String(Boolean(error)));return !error;
  }
  form.addEventListener('submit',event=>{const invalid=names.filter(name=>!validate(name));if(invalid.length){event.preventDefault();event.stopImmediatePropagation();const summary=form.querySelector('.emsf-form-error');summary.textContent='Controleer de gemarkeerde velden. Er is niets verzonden.';summary.hidden=false;form.elements.namedItem(invalid[0]).focus();}else{form.querySelector('.emsf-form-error').hidden=true;}});
  names.forEach(name=>{const field=form.elements.namedItem(name);field.addEventListener(name==='customer_confirmed'?'change':'input',()=>{if(field.getAttribute('aria-invalid')==='true')validate(name);});});
  const invalid=form.querySelector('[aria-invalid="true"]');if(invalid)invalid.focus();else{const summary=form.querySelector('.emsf-form-error:not([hidden])');if(summary)summary.focus();}
 });
})();
// Publication runs as one authenticated request; a separate read-only poll shows real stages.
document.addEventListener('submit',async function(event){
 const form=event.target;if(!form.matches('form[data-publication]')||event.defaultPrevented)return;
 event.preventDefault();event.stopImmediatePropagation();if(form.dataset.busy)return;
 const data=new FormData(form);data.set('action','emsf_publish');const action=data.get('site_action');
 form.dataset.busy='1';form.setAttribute('aria-busy','true');form.querySelectorAll('[type="submit"]').forEach(b=>b.disabled=true);
 form.querySelector('.emsf-progress')?.remove();const box=document.createElement('div');box.className='emsf-progress';box.setAttribute('role','status');const text=document.createElement('p');text.textContent='Controle wordt uitgevoerd…';box.append(text);const meter=document.createElement('progress');meter.setAttribute('aria-label','Publicatievoortgang');box.append(meter);form.append(box);
 const started=Date.now()-1000;let timer=null;let stopped=false;
 async function poll(){if(stopped)return;try{const url=new URL(form.dataset.ajax);url.searchParams.set('action','emsf_progress');url.searchParams.set('site_id',data.get('site_id'));url.searchParams.set('nonce',data.get('_wpnonce'));const r=await fetch(url,{credentials:'same-origin',cache:'no-store'});const result=await r.json();const p=result.data;if(!stopped&&result.success&&p?.message&&Date.parse(p.at)>=started){text.textContent=p.message+(p.total?' '+p.done+' van '+p.total:'');if(p.total){meter.max=p.total;meter.value=p.done;}else{meter.removeAttribute('value');}}}catch(e){}if(!stopped)timer=setTimeout(poll,2000);}
 if(!['preflight','status'].includes(action))poll();
 try{const response=await fetch(form.dataset.ajax,{method:'POST',body:data,credentials:'same-origin'});const result=await response.json();stopped=true;clearTimeout(timer);meter.remove();text.textContent=result.data?.message||'Controleer de status voordat je opnieuw begint.';const link=document.createElement('a');link.href=result.data?.url||location.href;link.textContent=result.success?(action==='preflight'?'Controle bekijken en verdergaan':'Publicatie bekijken'):'Status en herstelopties bekijken';if(action==='preflight'||action==='status'){
   const panel=form.closest('[role="tabpanel"]');const output=panel?.querySelector('[data-check-results]');if(output&&result.data?.html){output.innerHTML=result.data.html;}
   const changes=result.data?.changes||{};const revision=panel?.querySelector('[name="expected_revision"]');if(revision&&changes.token){revision.value=changes.token;}
   const review=panel?.querySelector('[data-replace-review]');if(review){const required=Boolean(changes.token&&(!changes.known||changes.changed));review.hidden=!required;const checkbox=review.querySelector('input');checkbox.disabled=!required;checkbox.required=required;checkbox.checked=false;}
   if(changes.token)panel?.querySelector('[data-check-first]')?.remove();
   if(!result.data?.html)box.append(link);
  }else{box.append(link);if(result.success)location.assign(link.href);}}
 catch(e){const checking=['preflight','status'].includes(action);text.textContent=checking?'De controle is afgebroken. Er is geen publicatie gestart. Herlaad de pagina en controleer opnieuw.':'De verbinding is onderbroken. De publicatie kan online nog lopen. Controleer eerst de status.';const link=document.createElement('a');link.href=location.href;link.textContent=checking?'Pagina herladen en opnieuw controleren':'Terug naar publicatiestatus';box.append(link);}
 finally{stopped=true;clearTimeout(timer);meter.remove();delete form.dataset.busy;form.removeAttribute('aria-busy');form.querySelectorAll('[type="submit"]').forEach(b=>b.disabled=false);}
});
document.addEventListener('submit',function(event){const form=event.target;if(!form.matches('.emsf-action-form'))return;if(form.dataset.busy){event.preventDefault();return;}form.dataset.busy='1';form.setAttribute('aria-busy','true');const status=document.createElement('p');status.className='emsf-progress';status.setAttribute('role','status');status.textContent='Bezig met deze site… Laat dit venster open tot de bevestiging verschijnt.';form.append(status);form.querySelectorAll('[type="submit"]').forEach(button=>{button.disabled=true;});});window.addEventListener('pageshow',()=>{document.querySelectorAll('.emsf-action-form').forEach(form=>{delete form.dataset.busy;form.removeAttribute('aria-busy');form.querySelectorAll('[type="submit"]').forEach(button=>button.disabled=false);form.querySelector('.emsf-progress')?.remove();});});

(function(){
 const list=document.querySelector('.emsf-tabs[role="tablist"]');if(!list)return;
 const tabs=Array.from(list.querySelectorAll('[role="tab"]'));
 function activate(tab,focus){
  tabs.forEach(item=>{const selected=item===tab;item.setAttribute('aria-selected',String(selected));item.tabIndex=selected?0:-1;const panel=document.getElementById(item.getAttribute('aria-controls'));if(panel)panel.hidden=!selected;});
  const selected=tab.getAttribute('aria-controls');document.querySelectorAll('input[name="return_tab"]').forEach(field=>field.value=selected);
  history.replaceState(null,'',tab.href);if(focus)tab.focus();
 }
 tabs.forEach((tab,index)=>{
  tab.addEventListener('click',event=>{if(event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;if(['toegang','gebruikers','email'].includes(tab.getAttribute('aria-controls'))&&new URL(location.href).searchParams.get('tab')!==tab.getAttribute('aria-controls'))return;event.preventDefault();activate(tab,false);});
  tab.addEventListener('keydown',event=>{let next=index;if(event.key==='ArrowDown'||event.key==='ArrowRight')next=(index+1)%tabs.length;else if(event.key==='ArrowUp'||event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=tabs.length-1;else return;event.preventDefault();if(['toegang','gebruikers','email'].includes(tabs[next].getAttribute('aria-controls'))&&new URL(location.href).searchParams.get('tab')!==tabs[next].getAttribute('aria-controls')){location.assign(tabs[next].href);return;}activate(tabs[next],true);});
 });
 window.addEventListener('pageshow',()=>{const id=new URL(location.href).searchParams.get('tab');const chosen=tabs.find(tab=>tab.getAttribute('aria-controls')===id)||tabs.find(tab=>tab.getAttribute('aria-selected')==='true');if(chosen)activate(chosen,false);});
})();

// Clipboard fallback keeps sharing usable where the browser blocks clipboard access.
document.querySelectorAll('[data-preview-copy]').forEach(button=>button.addEventListener('click',async()=>{
 const status=button.parentElement.querySelector('.emsf-copy-status');
 try{if(!navigator.clipboard)throw new Error('clipboard');await navigator.clipboard.writeText(button.dataset.previewCopy);status.textContent=' Link en code gekopieerd.';}
 catch(error){let field=button.parentElement.querySelector('textarea');if(!field){field=document.createElement('textarea');field.className='large-text';field.rows=3;field.readOnly=true;field.setAttribute('aria-label','Link en code om te kopiëren');button.after(field);}field.value=button.dataset.previewCopy;field.focus();field.select();status.textContent=' Selecteer en kopieer deze tekst.';}
}));

(function(){let target=null;document.querySelectorAll('[data-mail-template]').forEach(field=>field.addEventListener('focus',()=>target=field));document.querySelectorAll('[data-template-token]').forEach(button=>button.addEventListener('click',()=>{const status=document.getElementById('emsf-token-status');if(!target){status.textContent='Klik eerst op de plek in de template waar je de invulcode wilt toevoegen.';return;}target.setRangeText(button.dataset.templateToken,target.selectionStart,target.selectionEnd,'end');target.focus();status.textContent='Invulcode ingevoegd.';}));})();

(function(){document.querySelectorAll('[data-mail-step]').forEach(select=>{const form=select.closest('form');const sync=()=>{form.querySelectorAll('[data-mail-follow]').forEach(group=>{group.hidden=select.value!=='follow';group.querySelectorAll('input').forEach(input=>input.disabled=group.hidden||input.hasAttribute('data-unavailable'));});};select.addEventListener('change',sync);sync();});})();

// Status refresh is deliberately read-only and separate from content transfer.
document.querySelectorAll('[data-refresh-all]').forEach(button => {
    button.addEventListener('click', async () => {
        const rows = [...document.querySelectorAll('tr[data-connected="1"]')];
        const summary = document.querySelector('[data-refresh-summary]');
        button.disabled = true;
        let succeeded = 0, failed = 0;
        for (const [index, row] of rows.entries()) {
            summary.textContent = `Online status ophalen: ${index + 1} van ${rows.length}…`;
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 130000);
            try {
                const body = new URLSearchParams({action: 'emsf_refresh_status', nonce: button.dataset.nonce, site_id: row.dataset.siteId});
                const response = await fetch(button.dataset.url, {method: 'POST', body, credentials: 'same-origin', signal: controller.signal});
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error('Niet bereikbaar');
                row.querySelector('[data-online-state]').textContent = result.data.status;
                row.querySelector('[data-checked]').textContent = `Laatste controle: ${result.data.checked}`;
                row.querySelector('[data-status-error]').textContent = '';
                row.querySelector('[data-activity]').textContent = result.data.activity;
                succeeded++;
            } catch (error) {
                row.querySelector('[data-status-error]').textContent = 'Verversen mislukt; laatst bekende status.';
                failed++;
            } finally { clearTimeout(timer); }
        }
        summary.textContent = rows.length ? `${succeeded} bijgewerkt${failed ? ` · ${failed} niet bereikbaar` : ''}. Inhoud is niet gewijzigd.` : 'Geen gekoppelde sites in deze selectie.';
        button.disabled = false;
    });
});

// Delegation also handles buttons inserted after an environment check.
document.addEventListener('click', async event => {
 const button=event.target.closest('[data-software], [data-software-download]');if(!button)return;
 const panel=button.closest('[role="tabpanel"]');const form=panel?.querySelector('form[data-publication]');if(!form)return;
 const data=new FormData(form);
 if(button.hasAttribute('data-software-download')){
  const url=new URL(form.action);url.pathname=url.pathname.replace(/(?:admin-post|admin)\.php$/, 'admin-post.php');url.search='';url.searchParams.set('action','emsf_software_download');url.searchParams.set('site_id',data.get('site_id'));url.searchParams.set('_wpnonce',data.get('_wpnonce'));location.assign(url);return;
 }
 if(panel.dataset.softwareBusy)return;panel.dataset.softwareBusy='1';
 const buttons=panel.querySelectorAll('button,input[type="submit"]');buttons.forEach(item=>item.disabled=true);
 const note=document.createElement('p');note.setAttribute('role','status');note.textContent='Lokale software verzamelen en online bijwerken…';button.closest('[data-check-results]').prepend(note);
 data.set('action','emsf_software');data.set('component',button.dataset.software);
 try{
  const response=await fetch(form.dataset.ajax,{method:'POST',body:data,credentials:'same-origin'});const result=await response.json();
  if(!result.success)throw new Error(result.data?.message||'Bijwerken mislukt. Controleer de online status.');
  if(result.data.html)panel.querySelector('[data-check-results]').innerHTML=result.data.html;
  const success=document.createElement('p');success.setAttribute('role','status');success.textContent=result.data.message;panel.querySelector('[data-check-results]').prepend(success);
 }catch(error){note.textContent=error.message||'Verbinding onderbroken. Controleer online de versie vóór opnieuw bijwerken.';}
 finally{delete panel.dataset.softwareBusy;buttons.forEach(item=>item.disabled=false);}
});
