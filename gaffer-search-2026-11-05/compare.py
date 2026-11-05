import json,sys
a=json.load(open(sys.argv[1])); b=json.load(open(sys.argv[2]))
for k in a:
    x,y=a[k],b.get(k,{})
    same = x['total']==y.get('total') and x['ids']==y.get('ids')
    line=f"{k:20} {'same' if same else 'DIFF'}  {x['total']} -> {y.get('total')}"
    if not same:
        line+=f"\n    before {x['ids'][:30]}\n    after  {y.get('ids',[])[:30]}"
        if set(x['ids'])==set(y.get('ids',[])): line+="\n    (same products, other order)"
    if y.get('warnings')!=x['warnings']: line+=f"\n    warnings {x['warnings']} -> {y.get('warnings')}"
    print(line)
