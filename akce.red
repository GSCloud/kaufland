Red[]

source: read %akce.html
data: make block! 100
dq: #"^""
ws: charset " ^/^-^M"

store: func [value] [
        append data replace/all value "&nbsp;" #" "
]

parse source [
        some [
                thru <table class="wide discounts_table">
                some [
                        some ws
                        {<tr}
                        thru {<a}
                        thru {data-product="}
                        copy value to dq
                        (store value)
                        thru {title="}
                        copy value to dq
                        (store value)
                        thru <td class="text-left discounts_price">
                        thru <strong class="discount_price_value">
                        copy value to </strong>
                        (store value)
                        thru </tr>
                ]
        ]
]
