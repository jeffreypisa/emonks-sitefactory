const fs=require('fs'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync(require('path').join(__dirname,'../assets/importer.js'),'utf8');
let checks=0;function check(v,m){assert(v,m);checks++;}
async function fixture(step,fail){
 const listeners={},events={},nodes=[],attrs={};let calls=[],redirects=[],reloads=0,resolveRequest;
 const button={disabled:false};const form={dataset:{importStep:step,importAuto:'1',uploadUrl:'/wp-admin/admin-ajax.php'},matches:()=>true,getAttribute:k=>attrs[k],setAttribute:(k,v)=>attrs[k]=v,removeAttribute:k=>delete attrs[k],appendChild:n=>nodes.push(n),querySelector:q=>q==='[type=submit]'?button:nodes.find(n=>n.dataset.installStatus),requestSubmit:()=>listeners.submit({target:form,preventDefault(){}})};
 const doc={readyState:'complete',addEventListener:(k,fn)=>listeners[k]=fn,querySelector:()=>form,createElement:()=>({dataset:{},attributes:{},setAttribute(k,v){this.attributes[k]=v;},textContent:''})};
 class Data {constructor(){this.fields=new Map([['step',step],['operation','fixed-operation'],['_wpnonce','fixture-nonce']]);}set(k,v){this.fields.set(k,v);}get(k){return this.fields.get(k);}}
 const context={document:doc,window:{addEventListener:(k,fn)=>events[k]=fn},location:{origin:'https://fixture.test',assign:u=>redirects.push(u),reload:()=>reloads++},FormData:Data,fetch:async(url,opts)=>{calls.push({url,opts});return new Promise(resolve=>resolveRequest=resolve);}};
 vm.runInNewContext(source,context);
 check(calls.length===1,step+' auto starts exactly once');check(button.disabled,'Busy button disabled');events.pageshow({persisted:false});check(button.disabled,'Normal pageshow does not unlock in-flight operation');
 check(calls[0].opts.body.get('action')==='emsf_handover_step','Automatic phase endpoint');check(calls[0].opts.body.get('operation')==='fixed-operation','Operation bound');check(calls[0].opts.body.get('replace_confirmed')===undefined,'No extra replacement approval');
 resolveRequest({ok:!fail,status:fail?400:200,json:async()=>fail?{success:false,data:{message:'Fixture failure'}}:{success:true,data:{redirect:'/next'}}});
 await new Promise(r=>setImmediate(r));await new Promise(r=>setImmediate(r));
 if(fail){check(!button.disabled,'Error leaves resume button enabled');check(nodes[0].attributes.role==='alert'&&nodes[0].textContent==='Fixture failure','Server error visible');check(!redirects.length,'Failure does not advance');check(calls.length===1,'Failure does not loop');}
 else{check(redirects.length===1&&redirects[0]==='/next','Fresh request loads next phase');}
 events.pageshow({persisted:true});check(reloads===1,'Back/forward cache reloads actual state');
}
(async()=>{await fixture('software',false);await fixture('apply',false);await fixture('apply',true);console.log(checks+' automatic installer UI checks passed.');})();
