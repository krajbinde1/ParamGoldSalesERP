<x-app-layout>

<div class="container mt-4">

    <div class="card shadow">

        <div class="card-header">
            <h4>Add Dealer</h4>
        </div>

        <div class="card-body">

            <form method="POST" action="{{ route('dealers.store') }}">

                @csrf

                <div class="mb-3">
                    <label class="form-label">Firm Name <span class="text-danger">*</span></label>
                    <input type="text" name="firm_name" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Assigned Employee <span class="text-danger">*</span></label>
                    <input type="number" name="assigned_employee_id" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Owner Name</label>
                    <input type="text" name="owner_name" class="form-control">
                </div>

                <div class="mb-3">
                    <label class="form-label">Mobile <span class="text-danger">*</span></label>
                    <input type="text" name="mobile" class="form-control" required maxlength="10">
                </div>

                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="3"></textarea>
                </div>

                <div class="row g-2">

                    <div class="col-md-3">
                        <label class="form-label">State <span class="text-danger">*</span></label>
                        <select name="state" class="form-select" required>
                            <option value="{{ $state }}" selected>{{ $state }}</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">District <span class="text-danger">*</span></label>
                        <select name="district" id="dealer-district" class="form-select" required>
                            <option value="">Select District</option>
                            @foreach ($districts as $district)
                                <option value="{{ $district['name'] }}">
                                    {{ $district['former_name'] ? $district['name'].' ('.$district['former_name'].')' : $district['name'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Taluka <span class="text-danger">*</span></label>
                        <select name="taluka" id="dealer-taluka" class="form-select" required disabled>
                            <option value="">Select Taluka</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Village <span class="text-danger">*</span></label>
                        <input type="text" name="village" class="form-control" required>
                    </div>

                </div>

                <div class="row g-2 mt-2">
                    <div class="col-md-3">
                        <label class="form-label">Pincode</label>
                        <input type="text" name="pincode" class="form-control" maxlength="6">
                    </div>
                </div>

                <br>

                <button class="btn btn-success">
                    Save Dealer
                </button>

            </form>

        </div>

    </div>

</div>

<script>
    const dealerDistricts = @json(collect($districts)->mapWithKeys(fn ($district) => [$district['name'] => $district['talukas']]));
    const districtSelect = document.getElementById('dealer-district');
    const talukaSelect = document.getElementById('dealer-taluka');

    function fillTalukas(district, selected) {
        talukaSelect.innerHTML = '<option value="">Select Taluka</option>';
        const talukas = dealerDistricts[district] || [];
        talukaSelect.disabled = talukas.length === 0;
        talukas.forEach((taluka) => {
            const option = document.createElement('option');
            option.value = taluka;
            option.textContent = taluka;
            if (selected === taluka) option.selected = true;
            talukaSelect.appendChild(option);
        });
    }

    districtSelect.addEventListener('change', function () {
        fillTalukas(this.value, null);
    });
</script>

</x-app-layout>
