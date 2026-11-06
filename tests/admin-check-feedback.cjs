// Execute the real admin handler against an isolated DOM and intercepted transport.
const fs=require('node:fs');const vm=require('node:vm');const assert=require('node:assert/strict');
const script=fs.readFileSync(require('node:path').join(__dirname,'../assets/admin.js'),'utf8');
async function scenario(ready,changed){
 const listeners={};const revision={value:'old-token'};const checkbox={checked:true,disabled:true,required:false};const review={hidden:true,querySelector:()=>checkbox};const output={innerHTML:'old report'};const checkFirst={removed:false,remove(){this.removed=true;}};
 const old={textContent:'Controle afgerond: er zijn nog aandachtspunten.',removed:false,remove(){this.removed=true;}};
 const form={action:'https://local.example/wp-admin/admin-post.php',dataset:{ajax:'https://local.example/wp-admin/admin-ajax.php'},children:[old],querySelectorAll(selector){return selector==='.emsf-progress'?this.children.filter(n=>!n.removed):[];},append(node){this.children.push(node);}};
 const button={dataset:{software:'plugin'},disabled:false,hasAttribute:()=>false,closest:()=>panel};
 const panel={dataset:{},querySelector(selector){return ({'form[data-publication]':form,'[data-check-results]':output,'[name="expected_revision"]':revision,'[data-replace-review]':review,'[data-check-first]':checkFirst})[selector]||null;},querySelectorAll(){return [button];}};
 const document={querySelectorAll:()=>[],querySelector:()=>null,addEventListener(type,fn){(listeners[type]??=[]).push(fn);},createElement(){return {textContent:'',removed:false,setAttribute(){},remove(){this.removed=true;}};}};
 const message=ready?'Online software bijgewerkt. Gereed voor publicatie.':'Online software bijgewerkt. Er zijn nog aandachtspunten; bekijk het controleresultaat.';
 const context={document,window:{addEventListener(){}},FormData:class extends Map{constructor(){super([['site_id','2'],['_wpnonce','fixture']]);}},fetch:async()=>({json:async()=>({success:true,data:{message,html:ready?'fresh ready report':'fresh failed report',changes:{token:'fresh-token',known:true,changed}}})}),URL,location:{href:'https://local.example/',assign(){}},setTimeout,clearTimeout};
 vm.createContext(context);vm.runInContext(script,context);
 const handler=listeners.click.at(-1);await handler({target:{closest:()=>button}});
 assert.equal(old.removed,true,'Previous warning must be removed');
 const visible=form.children.filter(n=>!n.removed);assert.equal(visible.length,1,'Only one current feedback message');assert.equal(visible[0].textContent,message);
 assert.equal(output.innerHTML,ready?'fresh ready report':'fresh failed report');assert.equal(revision.value,'fresh-token');assert.equal(review.hidden,!changed);assert.equal(checkbox.required,changed);assert.equal(checkbox.disabled,!changed);assert.equal(checkbox.checked,false);assert.equal(checkFirst.removed,true);assert.equal(button.disabled,false);assert.equal(panel.dataset.softwareBusy,undefined);
}
(async()=>{await scenario(true,false);await scenario(false,true);console.log('2 admin feedback scenarios passed: stale status removed, fresh report and revision synchronized.');})().catch(error=>{console.error(error);process.exitCode=1;});
