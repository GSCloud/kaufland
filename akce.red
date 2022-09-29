Red[]

source: read %akce.html
data: make block! 10000
dq: #"^""
ws: charset " ^/^-^M"
store: func [value] [
    append data value
]

parse source [
    some [
        thru {<tr}
        thru {data-product="}
        copy value to dq
        (store "---") ; separator
        (store value) ; id
        thru {<a}
        thru {data-product="}
        copy value to dq
        (store value) ; product
        thru {<a}
        thru {data-shop="}
        copy value to dq
        (store value) ; shop
        thru <strong class="discount_price_value">
        copy value to {</strong>}
        (store value) ; price
        thru {</tr>}
    ]
]

foreach x data [print x]
