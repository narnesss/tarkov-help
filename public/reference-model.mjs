import {idOf} from './quest-model.mjs?v=page-content-20261017';
export const priceOf=item=>Number(item?.lastLowPrice)>0?Number(item.lastLowPrice):Number(item?.avg24hPrice)>0?Number(item.avg24hPrice):null;
export const slotsOf=item=>Math.max(1,(Number(item?.width)||1)*(Number(item?.height)||1));
export const sellPrice=item=>item?.marketTraderPrice??(Math.max(0,...(item?.sellToTrader||item?.sellFor||[]).map(p=>Number(p.priceRUB)||0))||null);
export function costOf(requirements,items){let total=0,missing=0;for(const r of requirements||[]){const item=items.get(idOf(r.item));let price;if(idOf(r.item)==='5449016a4bdc2d6f028b456f')price=1;else price=priceOf(item);if(price===null){missing++;continue;}total+=price*(Number(r.count)||0);}return {total,missing};}
export function recipeEstimate(recipe,items){const costs=costOf((recipe.requiredItems||[]).filter(r=>!r.attributes?.tool),items),offered=recipe.productItem||recipe.offeredItem,result=items.get(idOf(offered?.item)),unit=priceOf(result),revenue=unit===null?null:unit*(offered?.count||0);return {...costs,revenue,difference:!costs.missing&&revenue!==null?revenue-costs.total:null};}
