// GENERATED FILE. Do not edit.
// Source: src/lib/experiments/storefront-script.ts (generateStorefrontScript).
// storefront-asset.guard.test.ts fails the build if this drifts from it.
(function(){
'use strict';
// DOM READINESS. Schema-referenced JS loads deferred, but DOMContentLoaded may
// already have fired by the time this runs, and on a slow parse it may not have.
// Reading the config element or the swap target too early returns null and the
// whole feature silently does nothing, which is the same failure mode the
// sibling assets in this extension guard against. Same guard, same order.
var booted=false;
function boot(){
if(booted)return;
var el=document.getElementById("ace-ab-config");
if(!el)return;
booted=true;
var C;try{C=JSON.parse(el.textContent||"{}")}catch(e){return}
if(!C||typeof C!=="object")return;
var EXP=C.e,ARMS=C.arms,API=(C.api||"").replace(/\/$/,""),SUBJ=C.s,SEL=C.sel;
if(!EXP||!SUBJ||!ARMS||!ARMS.length)return;
// "els" is the multi-element list; "sel" is the single-description config an
// older server still writes. Normalising to one list here means the swap loop
// below has one shape to handle rather than two.
var WP=C.p==="wp",REP=C.rep||"";
var ELS=[];
if(C.els&&C.els.length){
  for(var ei=0;ei<C.els.length;ei++){
    var e0=C.els[ei];
    if(e0&&typeof e0.ct==='string'&&typeof e0.sel==='string'&&e0.sel)ELS.push({ct:e0.ct,sel:e0.sel})
  }
}
else if(SEL){ELS.push({ct:"product-description",sel:SEL})}
if(!ELS.length)return;
var ARMCK="_ace_arm_"+EXP,VIDCK="_ace_vid";

function h(s){var n=0;for(var i=0;i<s.length;i++){n=((n<<5)-n)+s.charCodeAt(i);n=n&n}return Math.abs(n)}
function gc(n){var m=n+"=",c=document.cookie.split(";");for(var i=0;i<c.length;i++){var s=c[i].trim();if(s.indexOf(m)===0)return decodeURIComponent(s.substring(m.length))}return null}
function sc(n,v,d){var e=new Date();e.setDate(e.getDate()+d);document.cookie=n+"="+encodeURIComponent(v)+";path=/;expires="+e.toUTCString()+";SameSite=Lax;Secure"}
function pick(vid){return ARMS[h(EXP+":"+vid)%ARMS.length]}

// CONSENT, PER PLATFORM, AND FAIL CLOSED ON EITHER.
//
// Shopify exposes customerPrivacy. WordPress exposes the WP Consent API
// (window.wp_has_consent), which is the wordpress.org standard that the consent
// plugins implement rather than each having its own. Neither is assumed to be
// there: with no consent API at all the answer is NO, so no cookie is written,
// nothing is reported, and every visitor reads the canonical copy. That is the
// same posture as the Shopify path and for the same reason -- the tested
// population is exactly the consented population.
//
// The two categories map across cleanly. WordPress "preferences" is storing a
// choice about how the site behaves for this visitor, which is what the arm
// cookie is. WordPress "statistics" is measurement, which is the report.
function cp(){return window.Shopify&&window.Shopify.customerPrivacy}
function can(fn){var c=cp();try{return !!(c&&typeof c[fn]==='function'&&c[fn]())}catch(e){return false}}
function wpCan(cat){try{return typeof window.wp_has_consent==='function'&&window.wp_has_consent(cat)===true}catch(e){return false}}
function analyticsOk(){return WP?wpCan('statistics'):can('analyticsProcessingAllowed')}
function prefsOk(){return WP?wpCan('preferences'):can('preferencesProcessingAllowed')}

// TWO DIFFERENT THINGS, AND CONFLATING THEM MIS-ATTRIBUTES CONVERSIONS.
// armShown is the arm THIS pageview actually renders, read once before anything
// can change it. arm is what the shopper is assigned to going forward, which on
// the pageview where consent is first given is NOT the same thing: they were
// assigned to b, but this page already rendered the canonical copy.
var armShown=gc(ARMCK);
var arm=armShown;
var applied=false;

// ONE FETCH, HOWEVER MANY ELEMENTS. An arm is a COMBINATION once a test varies
// more than one thing, so a request per element would put N round trips on a
// product pageview to fetch what the server already resolves together.
//
// The response carries two shapes at once. "elements" is what this asset
// reads;
// "html" is the single-description shape an older asset, in a theme nobody
// has updated, still reads. Reading "elements" with a fallback to "html"
// means this asset also keeps working against a deployment that has not
// shipped the new response yet, so neither side has to move first.
function apply(){
  if(applied||!armShown||armShown===ARMS[0])return;
  applied=true;
  if(!ELS.length)return;
  var u=API+"/api/public/experiments/copy?e="+encodeURIComponent(EXP)+"&s="+encodeURIComponent(SUBJ)+"&a="+encodeURIComponent(armShown);
  try{
    fetch(u,{credentials:'omit',mode:'cors'}).then(function(r){return r.ok?r.json():null}).then(function(d){
      if(!d)return;
      var got=[];
      if(d.elements&&d.elements.length){got=d.elements}
      else if(typeof d.html==='string'&&d.html){got=[{ct:ELS[0].ct,html:d.html}]}
      for(var i=0;i<got.length;i++){
        var g=got[i];
        if(!g||typeof g.html!=='string'||!g.html)continue;
        var sel=null;
        for(var j=0;j<ELS.length;j++){if(ELS[j].ct===g.ct){sel=ELS[j].sel;break}}
        // An element the config did not ask for is DROPPED, not guessed at. The
        // server decides what an arm contains; the theme decides where it goes,
        // and a swap with no selector has nowhere it belongs.
        if(!sel)continue;
        var t=document.querySelector(sel);
        if(t)t.innerHTML=g.html;
      }
    })['catch'](function(){});
  }catch(e){}
}

// Assignment needs preferences consent: remembering which version to show is a
// personalization choice. It applies from the NEXT pageview.
function assign(){
  if(arm||!prefsOk())return;
  var vid=gc(VIDCK);
  if(!vid){vid='v_'+Date.now()+'_'+Math.random().toString(36).slice(2);sc(VIDCK,vid,365)}
  arm=pick(vid);
  sc(ARMCK,arm,90);
}

// Reporting needs analytics consent, independently.
//
// Published as a Shopify CUSTOM EVENT, which is the only channel that reaches
// our web pixel. The pixel runs in a strict sandbox -- a web worker with no
// window and no document -- so a global set here would be invisible to it and
// would fail silently in production, which is the worst way for this to break.
// https://shopify.dev/docs/api/web-pixels-api/emitting-data
//
// Sends armShown, never arm: on the pageview where a shopper is first assigned
// they still read the canonical copy, so this pageview belongs to no arm and
// reports null. Sending the fresh assignment would credit it with a conversion
// the shopper never saw.
function publish(){
  if(!analyticsOk())return;
  if(WP){
    if(!REP)return;
    // The visitor id is NOT in this payload. It is already on the request as a
    // first-party cookie, so the plugin reads it server-side and hashes it
    // there with a site salt. Putting it in the body would mean the raw id
    // travelled twice for no gain, and it is the one value in this feature that
    // identifies a person.
    var body=JSON.stringify({e:EXP,s:SUBJ,arm:armShown||null});
    try{
      if(navigator.sendBeacon){
        navigator.sendBeacon(REP,new Blob([body],{type:"application/json"}))
      }else{
        fetch(REP,{method:"POST",keepalive:true,credentials:"same-origin",headers:{"Content-Type":"application/json"},body:body})
      }
    }catch(e){}
    return;
  }
  var a=window.Shopify&&window.Shopify.analytics;
  if(!a||typeof a.publish!=='function')return;
  try{a.publish('ace:arm_exposure',{experimentId:EXP,subjectId:SUBJ,arm:armShown||null})}catch(e){}
}

function sync(){apply();assign();publish()}

// Consent can arrive after this runs, on either platform, so each signal is
// listened for. Shopify fires visitorConsentCollected; the WP Consent API fires
// wp_consent_type_defined once a category is known and wp_listen_for_consent_change
// whenever one changes.
document.addEventListener('visitorConsentCollected',function(){sync()});
document.addEventListener('wp_consent_type_defined',function(){sync()});
document.addEventListener('wp_listen_for_consent_change',function(){sync()});

if(WP){sync()}
else if(cp()){sync()}
else if(window.Shopify&&window.Shopify.loadFeatures){
  try{window.Shopify.loadFeatures([{name:'consent-tracking-api',version:'0.1'}],function(err){if(!err)sync()})}catch(e){}
}
// No consent API at all: fail closed. The canonical copy stays, nothing is
// stored, nothing is reported.
}

if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',boot)}
else{boot()}
// The theme editor re-renders sections without a page load, which would
// otherwise leave a merchant previewing a stale block.
document.addEventListener('shopify:section:load',boot);
})();
