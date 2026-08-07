import{t as e}from"./wizardStore.sFJ7CU1j.js";var t=new AbortController;function n(){t.abort(),t=new AbortController;let n=t.signal,r={"&":`&amp;`,"<":`&lt;`,">":`&gt;`,'"':`&quot;`,"'":`&#39;`};function i(e){return String(e??``).replace(/[&<>"']/g,e=>r[e]??e)}let a=document.querySelector(`[data-allocation-step]`),o=a?.querySelector(`[data-allocation-options]`),s=a?.querySelector(`[data-allocation-empty]`),c=a?.querySelector(`[data-allocation-next]`),l=a?.querySelector(`[data-allocation-back]`);if(!a||!o||!s||!c||!l)return;let u=o,d=s,f=c,p=l,m=a.querySelector(`[data-allocation-status]`),h=a.querySelector(`[data-allocation-status-text]`),g=window.__LABELS||{},_=null,v=!1;function y(e){return`$${e.toFixed(0)}`}function b(){let t=e.getState().options;if(!t||t.length===0){d.classList.remove(`hidden`),u.innerHTML=``,f.disabled=!0;return}d.classList.add(`hidden`);let r=e.getState().roomType,a=t,o=!1;if(r){let e=t.filter(e=>e.rooms.length===1&&e.rooms[0].room.slug===r);e.length>0&&(o=t.some(e=>!(e.rooms.length===1&&e.rooms[0].room.slug===r)),a=v?t:e.slice(0,1))}let s=a.slice(0,4);if(u.innerHTML=s.map((e,t)=>{let n=e.rooms.length>1,r=!n,a=_===e,o=e.rooms.map(e=>i(e.room.displayName)).join(` + `),s=e.extrasTotal>0?`<span class="text-text-secondary-light dark:text-text-secondary-dark">${g.extras||`Extra guest`}: +${y(e.extrasTotal)}</span>`:``;return`
          <label
            data-allocation-option
            data-option-index="${t}"
            class="group flex flex-col sm:flex-row sm:items-center gap-4 p-5 rounded-2xl border transition-[border-color,background-color,box-shadow] duration-300 cursor-pointer ${a?`border-primary bg-primary/5 shadow-md shadow-primary/5 ring-1 ring-primary`:r?`border-black/10 dark:border-white/10 bg-surface-light dark:bg-surface-dark hover:border-primary/50`:`border-black/5 dark:border-white/5 bg-stone-50 dark:bg-stone-900/40 opacity-80`}"
          >
            <span class="flex items-center gap-3 flex-1 min-w-0">
              <input
                type="radio"
                name="allocation"
                class="w-4 h-4 text-primary focus:ring-primary cursor-pointer"
                ${a?`checked`:``}
                ${r?``:`disabled aria-disabled="true"`}
              />
              <span class="min-w-0">
                <span class="flex flex-wrap items-center gap-2">
                  <span class="font-display text-lg text-text-primary-light dark:text-white">${o}</span>
                  ${e.bestPrice?`<span class="text-[10px] font-mono font-bold uppercase tracking-wider text-secondary bg-secondary/10 border border-secondary/30 rounded-full px-2 py-0.5">${g.bestPrice||`Best price`}</span>`:``}
                  ${n?`<span class="text-[10px] font-mono uppercase tracking-wider text-text-secondary-light dark:text-text-secondary-dark">${(g.roomsCount||`{n} rooms`).replace(`{n}`,String(e.rooms.length))}</span>`:``}
                </span>
                <span class="block text-xs text-text-secondary-light dark:text-text-secondary-dark mt-1">
                  ${e.nights} ${g.nights||`nights`} - ${y(e.roomTotal)} ${s?` - `+s:``}
                </span>
                ${n?`<span class="block text-[11px] text-amber-600 dark:text-amber-400 mt-1.5">${g.multiRoomNote||``}</span>`:``}
              </span>
            </span>
            <span class="text-right flex-shrink-0">
              <span class="block font-display text-2xl text-primary">${y(e.total)}</span>
              <span class="block text-[10px] font-mono uppercase tracking-wider text-text-secondary-light dark:text-text-secondary-dark">${g.perNight||`/night`}</span>
            </span>
          </label>
        `}).join(``),o){let e=document.createElement(`button`);e.type=`button`,e.className=`mt-3 text-xs font-semibold uppercase tracking-wider text-primary hover:text-primary-dark underline underline-offset-4 cursor-pointer`,e.textContent=v?g.onlyThisRoom||``:g.alternatives||``,e.addEventListener(`click`,()=>{v=!v,b()},{signal:n}),u.appendChild(e)}u.querySelectorAll(`[data-allocation-option]`).forEach(t=>{t.addEventListener(`click`,()=>{let n=Number(t.dataset.optionIndex),r=s[n];!r||r.rooms.length>1||(_=r,e.setState({allocation:r}),f.disabled=!1,b())},{signal:n})})}p.addEventListener(`click`,()=>e.back(),{signal:n}),f.addEventListener(`click`,()=>{f.disabled||!_||e.next()},{signal:n});function x(){let t=e.getState().options;if(!t||t.length===0||_)return;let n=e.getState().roomType,r=n?t.find(e=>e.rooms.length===1&&e.rooms[0].room.slug===n):t.find(e=>e.rooms.length===1);r&&(_=r,e.setState({allocation:r}),f.disabled=!1)}x(),b();let S=e.subscribe(()=>{m&&h&&(e.getState().selecting?(m.classList.remove(`hidden`),m.classList.add(`flex`),h.textContent=g.availabilityChecking||`Checking availability...`,u.classList.add(`opacity-50`,`pointer-events-none`)):(m.classList.add(`hidden`),m.classList.remove(`flex`),u.classList.remove(`opacity-50`,`pointer-events-none`))),x(),b()});n.addEventListener(`abort`,()=>S())}document.addEventListener(`astro:page-load`,n),document.addEventListener(`astro:before-preparation`,()=>{t.abort()});