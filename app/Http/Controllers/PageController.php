<?php

namespace PixelCreativityBoard\Http\Controllers;

use PixelCreativityBoard\Donation;
use PixelCreativityBoard\Pixel;
use Illuminate\Http\Request;
use PixelCreativityBoard\Http\Requests;
use PixelCreativityBoard\Http\Controllers\Controller;
use Carbon\Carbon;
use PixelCreativityBoard\JustGiving;
use Illuminate\Support\Facades\Log;
use PixelCreativityBoard\PixelDonation;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PageController extends Controller
{
    /**
     * Display the homepage
     *
     * @param Request $request
     *
     * @return $this
     */
    public function index(Request $request)
    {
        $pixels = Pixel::getPixels();
        return view('home')->with(['pixels' => $pixels, 'justGivingUrl' => JustGiving::getDonationUrl()]);
    }

    /**
     * Called after a donation has been made
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response|\Illuminate\Routing\Redirector
     */
    public function donated(Request $request)
    {
        if ($request->has('donationId')) {
            $donationId = $request->get('donationId');

            //Get the donation details from JustGiving
            $donationDetails = JustGiving::getDonationDetails($donationId);

            //If the donation is found and valid
            if ($donationDetails != null && $donationDetails->pageShortName == JustGiving::$shortUrl) {

                $donation = null;

                //Check if the donation has  already been saved to the database
                if (Donation::where('just_giving_id', $donationDetails->id)->exists()) {
                    $donation = Donation::where('just_giving_id', $donationDetails->id)->first();
                } else {
                    $donation = new Donation();
                    $donation->just_giving_id =$donationDetails->id;
                    $donation->amount = $donationDetails->amount;
                    $donation->name = $donationDetails->donorDisplayName;
                    $donation->save();
                }

                //If the donation is still valid
                if ($donation->selected == false) {
                    $pixels = Pixel::getPixels();
                    $maxPixels = $donation->getMaxNumberOfPixels();
                    return view('select')->with([
                        'pixels' => $pixels,
                        'maxPixels' => $maxPixels,
                        'siteUrl' => env('SITE_URL'),
                        'donationId' => $donation->just_giving_id
                    ]);
                }
            }
        }

        return redirect('/');
    }

    /**
     * Save the selected pixels (using ajax)
     *
     * @param Request $request
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function save(Request $request)
    {
        //Get the donation
        $donation = Donation::donationWithJustGivingId($request->route('donationId'));

        //If the user has already selected their pixels
        if ($donation->selected) {
            return redirect('/');
        }

        //Loop through all the selected pixels
        foreach($request->all() as $pixel) {

            $savedPixel = Pixel::where('x', $pixel['x'])->where('y', $pixel['y'])->first();

            //Check the pixel has not already been selected
            if ($savedPixel->color != null) {
                throw new ConflictHttpException;
            }

            $savedPixel->color = $pixel['color'];
            $savedPixel->expires_at = Carbon::now()->addDays(env('NUM_DAYS', 7));
            $savedPixel->save();

            //Save the pixel donation
            $pixelDonation = new PixelDonation();
            $pixelDonation->pixel_id = $savedPixel->id;
            $pixelDonation->donation_id = $donation->id;
            $pixelDonation->color = $savedPixel->color;
            $pixelDonation->start_time = Carbon::now();
            $pixelDonation->end_time = $savedPixel->expires_at;
            $pixelDonation->save();
        }

        //Prevent duplicate selections
        $donation->selected = true;
        $donation->save();

        return response("SUCCESS", 200);
    }

}
