(function(){
    'use strict';
    var messages={software:'Thema en plugins installeren…',apply:'Inhoud en instellingen overzetten en website activeren…',release:'Website controleren en activeren…',restore:'Vorige inhoud terugzetten…',cleanup:'Tijdelijke bestanden opruimen…'};
    function reset(form){form.removeAttribute('aria-busy');var button=form.querySelector('[type=submit]');if(button){button.disabled=false;}}
    async function upload(form){
        var file=form.querySelector('[name=package]').files[0];if(!file){return;}
        var status=form.querySelector('[data-upload-status]');if(!status){status=document.createElement('p');status.dataset.uploadStatus='true';status.setAttribute('role','status');form.appendChild(status);}
        status.setAttribute('role','status');var progress=form.querySelector('progress');if(!progress){progress=document.createElement('progress');progress.setAttribute('aria-label','Pakket uploaden');form.appendChild(progress);}progress.max=file.size;progress.value=0;
        var key='emsf-upload:'+location.origin+':'+file.name+':'+file.size+':'+file.lastModified;
        var nonce=form.querySelector('[name=_wpnonce]').value,endpoint=form.dataset.uploadUrl;
        function remember(token){try{sessionStorage.setItem(key,token);}catch(e){}}
        function remembered(){try{return sessionStorage.getItem(key);}catch(e){return null;}}
        function forget(){try{sessionStorage.removeItem(key);}catch(e){}}
        async function call(phase,fields,blob){
            var data=new FormData();data.append('action','emsf_handover_upload');data.append('_wpnonce',nonce);data.append('phase',phase);Object.keys(fields||{}).forEach(function(k){data.append(k,fields[k]);});if(blob){data.append('chunk',blob,'upload.bin');}
            var response;try{response=await fetch(endpoint,{method:'POST',body:data,credentials:'same-origin'});}catch(e){throw new Error('De verbinding met de hosting is onderbroken. Klik opnieuw op Pakket uploaden om te hervatten.');}
            var result;try{result=await response.json();}catch(e){throw new Error('De hosting heeft het verzoek afgebroken (HTTP '+response.status+'). Klik opnieuw op Pakket uploaden om te hervatten.');}
            if(!response.ok||!result||!result.success){var error=new Error(result&&result.data&&result.data.message||'Upload geweigerd. Vernieuw de pagina en probeer opnieuw.');error.restartUpload=response.status===410;throw error;}return result.data;
        }
        try{
            status.textContent='Upload voorbereiden…';var token=remembered(),state;
            if(token){try{state=await call('status',{token:token});}catch(e){if(!e.restartUpload){throw e;}forget();token=null;}}
            if(!token){state=await call('start',{name:file.name,size:file.size,hosting_private:form.querySelector('[name=hosting_private]').checked?'1':'0'});token=state.token;remember(token);}
            if(state.redirect){forget();location.assign(state.redirect);return;}
            var offset=state.offset,chunkSize=state.chunk_size;progress.value=offset;
            while(offset<file.size){
                status.textContent='Pakket uploaden: '+Math.floor(offset/file.size*100)+'%';
                var result=await call('chunk',{token:token,offset:offset},file.slice(offset,Math.min(offset+chunkSize,file.size)));
                if(result.offset<=offset||result.offset>file.size){throw new Error('De uploadvoortgang wijkt af. Vernieuw de pagina en hervat.');}offset=result.offset;progress.value=offset;
            }
            status.textContent='Upload voltooid. Pakket uitpakken en controleren…';progress.removeAttribute('value');
            var completed=await call('finish',{token:token});forget();location.assign(completed.redirect);
        }catch(e){progress.value=typeof offset==='number'?offset:0;status.setAttribute('role','alert');status.textContent=e.message;reset(form);}
    }
    async function install(form){
        var status=form.querySelector('[data-install-status]');if(!status){status=document.createElement('p');status.dataset.installStatus='true';form.appendChild(status);}status.setAttribute('role','status');status.textContent=messages[form.dataset.importStep];
        var data=new FormData(form);data.set('action','emsf_handover_step');
        try{
            var response=await fetch(form.dataset.uploadUrl,{method:'POST',body:data,credentials:'same-origin'});var result;
            try{result=await response.json();}catch(e){throw new Error('De hosting heeft deze installatiestap onderbroken (HTTP '+response.status+'). Klik op Installatie hervatten.');}
            if(!response.ok||!result||!result.success){throw new Error(result&&result.data&&result.data.message||'Installatie gestopt. Klik op Installatie hervatten.');}
            location.assign(result.data.redirect);
        }catch(e){status.setAttribute('role','alert');status.textContent=e.message||'Verbinding onderbroken. Klik op Installatie hervatten.';reset(form);}
    }
    document.addEventListener('submit',function(event){
        var form=event.target;if(!form.matches('.emsf-import form[data-import-step]')){return;}
        if(form.getAttribute('aria-busy')==='true'){event.preventDefault();return;}
        if(form.dataset.importStep==='stage'){
            event.preventDefault();form.setAttribute('aria-busy','true');form.querySelector('[type=submit]').disabled=true;upload(form);return;
        }
        if(form.dataset.importAuto==='1'){event.preventDefault();form.setAttribute('aria-busy','true');form.querySelector('[type=submit]').disabled=true;install(form);return;}
        var message=messages[form.dataset.importStep];if(!message){return;}
        form.setAttribute('aria-busy','true');var button=form.querySelector('[type=submit]');if(button){button.disabled=true;}
        var status=document.createElement('p');status.setAttribute('role','status');status.textContent=message;form.appendChild(status);
    });
    function start(){var form=document.querySelector('.emsf-import form[data-import-auto="1"]');if(form){form.requestSubmit();}}
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',start,{once:true});}else{start();}
    window.addEventListener('pageshow',function(event){if(event.persisted){location.reload();}});
})();
